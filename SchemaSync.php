<?php
if ( ! function_exists("log_message") ) {
	function log_message ( $severity="debug", $message=NULL ) {
		if ( ! empty($message) ) error_log($_SERVER["argv"][0] . " - " . $severity . " - " . $message);
		echo "Error ($severity): $message\n";
		return true;
	}
}

class SchemaSync
{
	private $source_connection = NULL;
	private $comparison_connection = NULL;

    private $source_schema = array();
    private $comparison_schema = array();

	private function connect($server, $user, $password, $dbname)
	{
		$conn = mysql_connect($server, $user, $password) or die ("Can't connect to MySQL server '$user@$server' (using password '$password')\n");
		mysql_select_db($dbname, $conn);
		return $conn;
	}

	public function set_source_connection($server, $user, $password, $dbname)
	{
		if (false !== ($conn = $this->connect($server, $user, $password, $dbname))) {
			$this->source_connection = $conn;
			return true;
		}
		return false;
	}

	public function set_comparison_connection($server, $user, $password, $dbname)
	{
		if (false !== ($conn = $this->connect($server, $user, $password, $dbname))) {
			$this->comparison_connection = $conn;
			return true;
		}
		return false;
	}

	private function query($query, $conn)
	{
		$result = mysql_query($query, $conn) or die("Can not execute query: $query\n");
		return $result;
	}

	private function fetch($result)
	{
		return mysql_fetch_array($result);
	}

    private function show_create_table($table, $conn)
    {
        $result = $this->query("show create table `$table`", $conn);
        $table_info = $this->fetch($result);
        $text = $table_info[1];
        return $text . "\n";
    }

    private function show_create_field($field, $table, $conn)
    {
        $table_text = $this->show_create_table($table, $conn);
        $table_array = explode("\n", $table_text);

        foreach ($table_array as $line_num => $line) {
            $line_array = explode(' ', trim($line));

            if ($line_array[0] == $field || $line_array[0] == "`" . $field . "`") {
                return "ALTER TABLE `$table` ADD " . rtrim(trim($table_array[$line_num]), ',') . (isset($previous_field) ? " AFTER $previous_field" : " FIRST") . ";\n";
            }
            $previous_field = $line_array[0];
        }
    }

    private function show_alter_field($field, $table, $conn)
    {
        $table_text = $this->show_create_table($table, $conn);
        $table_array = explode("\n", $table_text);

        foreach ($table_array as $line_num => $line) {
            $line_array = explode(' ', trim($line));

            if ($line_array[0] == $field || $line_array[0] == "`" . $field . "`") {
                return "ALTER TABLE `$table` CHANGE `$field` " . rtrim(trim($table_array[$line_num]), ',') . ";\n"; // . (isset($previous_field) ? " AFTER $previous_field" : " FIRST") . ";\n";
            }
            $previous_field = $line_array[0];
        }
    }

	private function schema($conn)
	{
		$schema = array();

		$query = "show tables";
		$result = $this->query($query, $conn);

		while ($table_info = $this->fetch($result)) {
			$table_name = $table_info[0];

			$query = "describe `$table_name`";
			$result2 = $this->query($query, $conn);

			$schema[$table_name] = array();

			while ($column_info = $this->fetch($result2)) {
				foreach ($column_info as $key => $value) {
					unset($column_info[$key]);
					$new_key = strtolower($key);
					$column_info[$new_key] = $value;
				}
				reset($column_info);

				$col_name = $column_info["field"];
				unset($column_info["field"]);

				$schema[$table_name][$col_name] = $column_info;
			}

			ksort($schema[$table_name]);  // sort columns alphabetically
		}

		ksort($schema); // sort tables alphabetically

		return $schema;
	}

	private function diff($schema1, $schema2)
	{
		$missing_tables = array();
		$missing_columns = array();
		$differences = array();
		foreach ($schema1 as $table => $columns) {
			if (!isset($schema2[$table])) {
				$missing_tables[$table] = $schema1[$table];
				log_message("info", "Table missing: $table\n");
				continue;
			}

			foreach ($columns as $col_name => $col_data) {
				if (!isset($schema2[$table][$col_name])) {
					if (!isset($missing_columns[$table])) $missing_columns[$table] = array();
					$missing_columns[$table][$col_name] = $col_data;
					log_message("info", "Column missing from table '$table' - '$col_name'\n");
					continue;
				}

				unset($col_data["key"]); // ignore indexes for now
				foreach ($col_data as $key => $value) {
					if ($value != $schema2[$table][$col_name][$key]) {
						if (!isset($differences[$table])) $differences[$table] = array();
						if (!isset($differences[$table][$col_name])) $differences[$table][$col_name] = array();
						$differences[$table][$col_name] = $schema1[$table][$col_name];
						//echo "Column `$table`.`$col_name` (Good: `$col_name` " . join(' ',$col_data) . " - Bad: `$col_name` " . join(' ',$schema2[$table][$col_name]) . ")\n";
						continue 2;
					}
				}
			}
		}

		return array("missing_tables" => $missing_tables, "missing_columns" => $missing_columns, "column_differences" => $differences);
	}

    public function compare () {
        $this->source_schema = $this->schema($this->source_connection);
        $this->comparison_schema = $this->schema($this->comparison_connection);

        $diff = $this->diff( $this->source_schema, $this->comparison_schema);

        $queries=array();

        foreach ( $diff["missing_tables"] as $table => $table_info ) {
            $queries[]=$this->show_create_table($table,$this->source_connection);
        }

        foreach ( $diff["missing_columns"] as $table => $column_data ) {
            foreach ( $column_data as $column_name => $format ) {
                $queries[]=$this->show_create_field($column_name, $table, $this->source_connection);
            }
        }

        foreach ( $diff["column_differences"] as $table => $column_data ) {
            foreach ( $column_data as $column_name => $format ) {
                $queries[]=$this->show_alter_field($column_name, $table, $this->source_connection);
            }
        }

        $reverse_diff = $this->diff( $this->comparison_schema, $this->source_schema );
        foreach ( $reverse_diff["missing_tables"] as $table => $table_info ) {
            $queries[]="DROP TABLE `$table`;\n";
        }

        foreach ( $reverse_diff["missing_columns"] as $table => $column_data ) {
            foreach ( $column_data as $column_name => $format ) {
                $queries[]="ALTER TABLE `$table` DROP COLUMN `$column_name`;\n";
            }
        }

        return $queries;
    }
}

$diff=new SchemaSync();
$diff->set_source_connect("staging.db","username","password","dbname");
$diff->set_compariter_connect("dev.db","username","password","dbname");
echo join("\n", $diff->compare() );