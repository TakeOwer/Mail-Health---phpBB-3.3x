<?php
/**
 *
 * Mail Health. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\mailhealth\migrations;

/**
 * Start, end and duration of the last run, so the check-up can tell a run
 * that finished from one the host killed. Created by the code on first use
 * too; registered here so they are removed on uninstall.
 */
class v_1_0_15 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['mailhealth_run_started'], $this->config['mailhealth_run_finished'], $this->config['mailhealth_run_seconds']);
	}

	public static function depends_on()
	{
		return ['\salvocortesiano\mailhealth\migrations\v_1_0_14'];
	}

	public function update_data()
	{
		return [
			['config.add', ['mailhealth_run_started', 0, true]],
			['config.add', ['mailhealth_run_finished', 0, true]],
			['config.add', ['mailhealth_run_seconds', 0, true]],
		];
	}
}
