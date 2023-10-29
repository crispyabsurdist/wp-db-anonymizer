<?php
/*
Plugin Name: WP DB Anon Plugin
Description: Anonymizes personal data in the WordPress database during export.
Version: 1.0
Author: Markus Hedenborn [markus.hedenborn@triggerfish.se]
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

	public function anonymizeDatabase($assoc_args)
	{
		$dryRun = isset($assoc_args['dry-run']);

		// TODO: Create a real dry-run mode
		if ($dryRun) {
			WP_CLI::log("Performing a dry run...");
			WP_CLI::log("Simulating database export...");
			WP_CLI::log("Simulating data anonymization...");
			WP_CLI::success("Dry run complete. No actual changes were made.");
			return;
		}

		$currentPath = getcwd();

		if (!$currentPath) {
			WP_CLI::error("Failed to get the current working directory.");
			return;
		}

		$dbName = DB_NAME;
		$exportPath = $currentPath . '/' . $dbName . '.sql';
		$anonExportPath = $currentPath . '/' . $dbName . '_anonymized.sql';

		if (!is_writable($currentPath)) {
			WP_CLI::error("Export path is not writable: $exportPath");
			return;
		}

		// here is the smart part ;)
		// Step 1: Export the current database as a backup
		$this->exportDatabase($exportPath);

		// Step 2: Anonymize data in the current database
		if (!$this->anonymizeDataInDatabase()) {
			WP_CLI::error("Failed to anonymize data in the database.");
			return;
		}

		// Step 3: Export the anonymized database
		$this->exportDatabase($anonExportPath);

		// Step 4: Restore the original database from the backup
		$this->restoreDatabase($exportPath);

		WP_CLI::success("The data is now as fake as the Kardashians. Anonymized data -> {$anonExportPath}");
	}

	protected function exportDatabase($sqlFile)
	{
		exec("wp db export {$sqlFile}");
	}

	protected function restoreDatabase($sqlFile)
	{
		exec("wp db import {$sqlFile}", $output, $return_var);

		if ($return_var !== 0) {
			WP_CLI::error("Failed to import the original database from the backup.");
			return false;
		}

		if (!unlink($sqlFile)) {
			WP_CLI::warning("Could not delete the original SQL file. You might want to remove it manually.");
		} else {
			WP_CLI::success("Original SQL file deleted successfully.");
		}

		return true;
	}

	protected function anonymizeDataInDatabase()
	{
		global $wpdb;

		$users = $wpdb->get_results("SELECT ID FROM {$wpdb->users}");

		if (!$users) {
			WP_CLI::error("No users found in the database.");
			return false;
		}

		// TODO: Add support for Woocommerce data
		foreach ($users as $user) {
			$new_user_login = $this->faker->unique()->userName;
			$new_user_nicename = $this->faker->unique()->userName;
			$new_user_email = $this->faker->unique()->safeEmail;
			$new_display_name = $this->faker->name;

			if ($user->ID == 1) {
				continue;
			}

			$update_result = $wpdb->update(
				$wpdb->users,
				[
					'user_login' => $new_user_login,
					'user_nicename' => $new_user_nicename,
					'user_email' => $new_user_email,
					'display_name' => $new_display_name
				],
				[
					'ID' => $user->ID
				]
			);

			if (false === $update_result) {
				WP_CLI::error("Failed to update data for user with ID {$user->ID}.");
				return false;
			}
		}

		WP_CLI::success("All users anonymized.");
		return true;
	}
}

if (defined('WP_CLI') && WP_CLI) {
	WP_CLI::add_command('anon-database', [new DatabaseAnonymizer(), 'anonymizeDatabase']);
}
