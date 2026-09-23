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
 * Reading position in the mailbox: highest UID examined and the folder's
 * UIDVALIDITY. The code creates these on first use as well, so the files can
 * run before this migration; the migration makes them part of the install
 * and removes them on uninstall.
 */
class v_1_0_14 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['mailhealth_imap_last_uid'], $this->config['mailhealth_imap_uidvalidity']);
	}

	public static function depends_on()
	{
		return ['\salvocortesiano\mailhealth\migrations\v_1_0_8'];
	}

	public function update_data()
	{
		return [
			['config.add', ['mailhealth_imap_last_uid', 0, true]],
			['config.add', ['mailhealth_imap_uidvalidity', 0, true]],
		];
	}
}
