<?php
/*
Plugin Name: WP DB Anon Plugin
Description: Anonymizes personal data in the WordPress database during export.
Version: 1.0
Author: Markus Hedenborn
Mail: markus.hedenborn@triggerfish.se
*/

if (!defined('ABSPATH')) {
	die;
}

require_once __DIR__ . '/vendor/autoload.php';

use Faker\Factory;

class DatabaseAnonymizer
{
	protected $faker;

	public function __construct()
	{
		$this->faker = Factory::create();
	}

	public function anonymizeDatabase()
	{
		$currentPath = getcwd();

		if (!$currentPath) {
			WP_CLI::error("Failed to get the current working directory.");
			return;
		}

		$dbName = DB_NAME;
		$tempDbName = $dbName . '_temp_' . date("Y_m_d_H_i_s");
		$exportPath = $currentPath . '/' . $dbName . '.sql';
		$anonExportPath = $currentPath . '/' . $dbName . '_anonymized.sql';

		if (!is_writable($currentPath)) {
			WP_CLI::error("Export path is not writable: $exportPath");
			return;
		}

		// Step 1: Export the current production database to an SQL dump
		$this->exportDatabase($exportPath);

		// Step 2: Create a temporary database and import the SQL dump into it
		$this->createTempDatabase($tempDbName);
		$this->importToDatabase($tempDbName, $exportPath);

		// Step 3: Anonymize data in the temporary database
		$this->anonymizeDataInTempDatabase($tempDbName);

		// Step 4: Export the anonymized data from the temporary database
		$this->exportFromTempDatabase($tempDbName, $anonExportPath);

		// Step 5: Delete the temporary database and the original dump
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
		exec("mysql -u " . DB_USER . " -p'" . DB_PASSWORD . "' -e 'CREATE DATABASE {$tempDbName}'");
	}

	protected function importToDatabase($tempDbName, $sqlFile)
	{
		exec("mysql -u " . DB_USER . " -p'" . DB_PASSWORD . "' {$tempDbName} < {$sqlFile}");
	}

	protected function anonymizeDataInTempDatabase($tempDbName)
	{
		$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, $tempDbName);

		$result = $mysqli->query("SELECT ID FROM wp_users");

		if ($result->num_rows === 0) {
			WP_CLI::error("No users found in the database.");
			return;
		}

		while($user = $result->fetch_object()) {
			$new_user_login = $this->faker->unique()->userName;
			$new_user_nicename = $this->faker->unique()->userName;
			$new_user_email = $this->faker->unique()->safeEmail;
			$new_display_name = $this->faker->name;

			if ($user->ID == 1) {
				continue;
			}

			$update = $mysqli->query("UPDATE wp_users SET user_login = '{$new_user_login}', user_nicename = '{$new_user_nicename}', user_email = '{$new_user_email}', display_name = '{$new_display_name}' WHERE ID = {$user->ID}");

			if (!$update) {
				WP_CLI::error("Failed to update data for user with ID {$user->ID}.");
			}
		}

		$mysqli->close();
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
