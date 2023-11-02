<?php
/*
Plugin Name: WP DB Anon Plugin
Description: Anonymizes personal data in the WordPress database during export.
Version: 1.0
Author: Markus Hedenborn
*/


namespace Triggerfish\WPDBAnon;

use Faker\Factory;
use mysqli;
use WP_CLI;

if (!defined('ABSPATH')) {
	die;
}

require_once __DIR__ . '/vendor/autoload.php';

class DatabaseAnonymizer
{
	protected $faker;

	public function __construct()
	{
		$this->faker = Factory::create();
	}

	public function anonymizeDatabase()
	{
		global $wpdb;

		$this->anonymizeData(DB_NAME, $wpdb->base_prefix);

		if (is_multisite()) {
			$blogs = $wpdb->get_results("SELECT blog_id FROM {$wpdb->blogs}", ARRAY_A);

			foreach ($blogs as $blog) {
				$table_prefix = $wpdb->get_blog_prefix($blog['blog_id']);
				$this->anonymizeUserMetaData(DB_NAME, $table_prefix);
			}
		}
	}

	protected function anonymizeData($dbName, $table_prefix)
	{
		$currentPath = getcwd();

		if (!$currentPath) {
			WP_CLI::error("Failed to get the current working directory.");
			return;
		}

		$tempDbName = $dbName . '_temp_' . date("Y_m_d_H_i_s");
		$exportPath = $currentPath . '/' . $dbName . '.sql';
		$anonExportPath = $currentPath . '/' . $dbName . '_anonymized.sql';

		if (!is_writable($currentPath)) {
			WP_CLI::error("Export path is not writable: $exportPath");
			return;
		}

		WP_CLI::log("Exporting the database to SQL file...");
		$this->exportDatabase($exportPath);

		WP_CLI::log("Creating temporary database...");
		$this->createTempDatabase($tempDbName);

		WP_CLI::log("Importing data to temporary database...");
		$this->importToDatabase($tempDbName, $exportPath);

		WP_CLI::log("Anonymizing data in the temporary database...");
		$this->anonymizeDataInTempDatabase($tempDbName, $table_prefix);

		WP_CLI::log("Exporting anonymized data from temporary database...");
		$this->exportFromTempDatabase($tempDbName, $anonExportPath);

		WP_CLI::log("Deleting temporary database...");
		$this->deleteTempDatabase($tempDbName);

		if (file_exists($exportPath)) {
			unlink($exportPath);
		}

		WP_CLI::success("Data anonymized and stored at {$anonExportPath}.");
	}

	protected function exportDatabase($sqlFile)
	{
		exec("wp db export {$sqlFile}");
	}

	protected function createTempDatabase($tempDbName)
	{
		$escapedDbUser = escapeshellarg(DB_USER);
		$escapedDbPass = escapeshellarg(DB_PASSWORD);

		exec("mysql -u {$escapedDbUser} -p{$escapedDbPass} -e 'CREATE DATABASE {$tempDbName}' 2>&1", $output, $return_var);
		if ($return_var !== 0) {
			WP_CLI::error("Failed to create temporary database: " . implode("\n", $output));
		}
	}

	protected function importToDatabase($tempDbName, $sqlFile)
	{
		exec("mysql -u " . DB_USER . " -p'" . DB_PASSWORD . "' {$tempDbName} < {$sqlFile}");
	}

	protected function anonymizeDataInTempDatabase($tempDbName, $table_prefix)
	{
		$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, $tempDbName);

		if ($mysqli->connect_error) {
			WP_CLI::error("Failed to connect to MySQL: " . $mysqli->connect_error);
			return;
		}

		$result = $mysqli->query("SELECT ID FROM {$table_prefix}users");

		if (!$result) {
			WP_CLI::error("Failed to fetch users: " . $mysqli->error);
			return;
		}

		if ($result->num_rows === 0) {
			WP_CLI::error("No users found in the database.");
			return;
		}

		WP_CLI::log("Anonymizing user data...");

		if ($this->doesTableExist($mysqli, "{$table_prefix}users")) {
			while ($user = $result->fetch_object()) {
				$new_user_login = $mysqli->real_escape_string($this->faker->unique()->userName);
				$new_user_nicename = $mysqli->real_escape_string($this->faker->unique()->userName);
				$new_user_email = $mysqli->real_escape_string($this->faker->unique()->safeEmail);
				$new_display_name = $mysqli->real_escape_string($this->faker->name);

				//TODO: maybe add a flag to set username to skip?
				if ($user->ID == 1) {
					continue; // Skip admin user.
				}

				$update = $mysqli->query("UPDATE {$table_prefix}users SET user_login = '{$new_user_login}', user_nicename = '{$new_user_nicename}', user_email = '{$new_user_email}', display_name = '{$new_display_name}' WHERE ID = {$user->ID}");

				if (!$update) {
					WP_CLI::error("Failed to update data for user with ID {$user->ID}: " . $mysqli->error);
				}
			}
		} else {
			WP_CLI::warning("The table {$table_prefix}users does not exist. Continuing with the next steps.");
		}

		$this->anonymizeUserMetaData($tempDbName, $table_prefix);

		$mysqli->close();
	}

	protected function anonymizeUserMetaData($tempDbName, $table_prefix)
	{
		$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, $tempDbName);

		if ($mysqli->connect_error) {
			WP_CLI::error("Failed to connect to MySQL: " . $mysqli->connect_error);
			return;
		}

		if (!$this->doesTableExist($mysqli, "{$table_prefix}usermeta")) {
			WP_CLI::warning("The table {$table_prefix}usermeta does not exist. Continuing with the next steps.");
			$mysqli->close();
			return;
		}

		$userIdsResult = $mysqli->query("SELECT DISTINCT user_id FROM {$table_prefix}usermeta");

		if (!$userIdsResult) {
			WP_CLI::error("Failed to fetch usermeta: " . $mysqli->error);
			$mysqli->close();
			return;
		}

		WP_CLI::log("Anonymizing user meta data...");

		while ($meta = $userIdsResult->fetch_object()) {
			$meta_keys_to_anonymize = ['first_name', 'last_name', 'nickname', 'description'];

			foreach ($meta_keys_to_anonymize as $meta_key) {
				$new_meta_value = $this->getAnonymizedMetaValue($meta_key, $mysqli);

				$update_result = $mysqli->query("UPDATE {$table_prefix}usermeta SET meta_value = '{$new_meta_value}' WHERE user_id = {$meta->user_id} AND meta_key = '{$meta_key}'");

				if (!$update_result) {
					WP_CLI::error("Failed to update usermeta for user_id {$meta->user_id} and meta_key {$meta_key}: " . $mysqli->error);
				}
			}
		}

		$mysqli->close();
	}

	private function getAnonymizedMetaValue($meta_key, $mysqli)
	{
		$new_meta_value = '';
		if ($meta_key === 'first_name' || $meta_key === 'last_name') {
			$new_meta_value = $mysqli->real_escape_string($this->faker->name);
		} elseif ($meta_key === 'nickname' || $meta_key === 'description') {
			$new_meta_value = $mysqli->real_escape_string($this->faker->userName);
		}
		return $new_meta_value;
	}

	protected function doesTableExist($mysqli, $tableName)
	{
		$result = $mysqli->query("SHOW TABLES LIKE '{$tableName}'");
		return $result && $result->num_rows > 0;
	}

	protected function exportFromTempDatabase($tempDbName, $sqlFile)
	{
		exec("mysqldump -u " . DB_USER . " -p'" . DB_PASSWORD . "' {$tempDbName} > {$sqlFile}");
	}

	protected function deleteTempDatabase($tempDbName)
	{
		exec("mysql -u " . DB_USER . " -p'" . DB_PASSWORD . "' -e 'DROP DATABASE {$tempDbName}'");
	}
}

if (defined('WP_CLI') && WP_CLI) {
	WP_CLI::add_command('anon-database', [new DatabaseAnonymizer(), 'anonymizeDatabase']);
}
