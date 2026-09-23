<?php
/**
 *
 * Mail Health. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\mailhealth\service;

/**
 * Tells the member what happened, by a route that still works.
 *
 * When an address bounces, e-mail is exactly what cannot be used, so the
 * warning goes out as a private message: the member reads it the next time
 * he logs in, and it stays in his inbox.
 *
 * When he changes the address, a verification e-mail goes to the NEW one.
 * Clicking its link proves the address works, which is worth more than
 * waiting the probation period out.
 */
class notifier
{
	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\language\language_file_loader */
	protected $loader;

	/** @var array Language objects already built, by ISO code */
	protected $languages = [];

	/** @var \phpbb\routing\helper */
	protected $routing;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\db\driver\driver_interface $db,
		\phpbb\language\language $language,
		\phpbb\language\language_file_loader $loader,
		\phpbb\routing\helper $routing,
		$root_path,
		$php_ext
	)
	{
		$this->config = $config;
		$this->db = $db;
		$this->language = $language;
		$this->loader = $loader;
		$this->routing = $routing;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * The member's address stopped working and his e-mail has been switched
	 * off. Sent once, when he enters the bounced state.
	 */
	public function notify_bounced(array $user_row)
	{
		if (!$this->config['mailhealth_pm_enabled'])
		{
			return;
		}

		$language = $this->language_for($this->user_lang($user_row));

		$this->send_pm(
			(int) $user_row['user_id'],
			$language->lang('MAILHEALTH_PM_BOUNCED_SUBJECT'),
			$language->lang('MAILHEALTH_PM_BOUNCED_BODY', $user_row['username'], $user_row['user_email'], $this->board_url('ucp.' . $this->php_ext . '?i=ucp_profile&mode=reg_details'))
		);
	}

	/**
	 * The member changed his address. A verification message goes to the new
	 * one; the link in it confirms the address at once.
	 *
	 * @return string The token, so the caller can store it
	 */
	public function notify_changed(array $user_row, $new_email)
	{
		$token = bin2hex(random_bytes(16));

		if ($this->config['mailhealth_verify_enabled'])
		{
			$this->send_verification($user_row, $new_email, $token);
		}

		if ($this->config['mailhealth_pm_enabled'])
		{
			$language = $this->language_for($this->user_lang($user_row));

			$this->send_pm(
				(int) $user_row['user_id'],
				$language->lang('MAILHEALTH_PM_CHANGED_SUBJECT'),
				$language->lang('MAILHEALTH_PM_CHANGED_BODY', $user_row['username'], $new_email)
			);
		}

		return $token;
	}

	/**
	 * @return bool
	 */
	protected function send_verification(array $user_row, $new_email, $token)
	{
		if (empty($this->config['email_enable']))
		{
			return false;
		}

		$link = $this->routing_link((int) $user_row['user_id'], $token);

		return $this->deliver_email($new_email, $user_row['username'], $this->user_lang($user_row), [
			'USERNAME'		=> htmlspecialchars_decode($user_row['username']),
			'U_CONFIRM'		=> $link,
			'CONFIRM_DAYS'	=> (int) $this->config['mailhealth_confirm_days'],
		]);
	}

	/**
	 * Absolute address of the confirmation page, built by phpBB's router.
	 *
	 * @return string
	 */
	protected function routing_link($user_id, $token)
	{
		return $this->routing->route('salvocortesiano_mailhealth_confirm', [
			'user_id'	=> (int) $user_id,
			'token'		=> $token,
		], false, false, \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
	}

	/**
	 * Hands the verification message to phpBB. Kept on its own so the rest
	 * can be tested without a mail server.
	 *
	 * @return bool
	 */
	protected function deliver_email($to, $username, $iso, array $vars)
	{
		if (!class_exists('messenger'))
		{
			include($this->root_path . 'includes/functions_messenger.' . $this->php_ext);
		}

		$messenger = new \messenger(false);
		$messenger->template('@salvocortesiano_mailhealth/mailhealth_verify', $iso);
		$messenger->to($to, $username);
		$messenger->assign_vars($vars);
		$messenger->send(NOTIFY_EMAIL);
		$messenger->save_queue();

		return true;
	}

	/**
	 * Private message from the account configured in the ACP, or from the
	 * oldest founder when none is set.
	 *
	 * @return bool True when the message was handed over
	 */
	protected function send_pm($user_id, $subject, $body)
	{
		if (!empty($this->config['privmsg_disable']))
		{
			return false;
		}

		$sender = $this->get_sender();

		if ($sender === false || (int) $sender['user_id'] === (int) $user_id)
		{
			return false;
		}

		return $this->deliver_pm($sender, (int) $user_id, $subject, $body);
	}

	/**
	 * Hands the private message to phpBB. Kept on its own so the rest can be
	 * tested without a database.
	 *
	 * @return bool
	 */
	protected function deliver_pm(array $sender, $user_id, $subject, $body)
	{
		if (!function_exists('submit_pm'))
		{
			include($this->root_path . 'includes/functions_privmsgs.' . $this->php_ext);
		}

		if (!class_exists('parse_message'))
		{
			include($this->root_path . 'includes/message_parser.' . $this->php_ext);
		}

		$parser = new \parse_message();
		$parser->message = $body;
		$parser->parse(true, true, true, false, false, true, true);

		$pm_data = [
			'from_user_id'		=> (int) $sender['user_id'],
			'from_user_ip'		=> '',
			'from_username'		=> $sender['username'],
			'enable_sig'		=> false,
			'enable_bbcode'		=> true,
			'enable_smilies'	=> false,
			'enable_urls'		=> true,
			'icon_id'			=> 0,
			'bbcode_bitfield'	=> $parser->bbcode_bitfield,
			'bbcode_uid'		=> $parser->bbcode_uid,
			'message'			=> $parser->message,
			'address_list'		=> ['u' => [(int) $user_id => 'to']],
		];

		submit_pm('post', $subject, $pm_data, false);

		return true;
	}

	/**
	 * @return array|false
	 */
	protected function get_sender()
	{
		$configured = (int) $this->config['mailhealth_pm_sender'];

		if ($configured > 0)
		{
			$sql = 'SELECT user_id, username FROM ' . USERS_TABLE . ' WHERE user_id = ' . $configured;
			$result = $this->db->sql_query($sql);
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);

			if ($row)
			{
				return $row;
			}
		}

		$sql = 'SELECT user_id, username
			FROM ' . USERS_TABLE . '
			WHERE user_type = ' . USER_FOUNDER . '
			ORDER BY user_id ASC';
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ? $row : false;
	}

	/**
	 * Sends both messages to one member, on request from the ACP: exactly
	 * the texts a real member would get, in that member's own language, with
	 * a token that is never stored.
	 *
	 * @return array ['pm' => bool, 'email' => bool, 'lang' => string]
	 */
	public function send_test(array $user_row, $email)
	{
		$language = $this->language_for($this->user_lang($user_row));

		$pm = $this->send_pm(
			(int) $user_row['user_id'],
			$language->lang('MAILHEALTH_PM_BOUNCED_SUBJECT'),
			$language->lang('MAILHEALTH_PM_BOUNCED_BODY', $user_row['username'], $email, $this->board_url('ucp.' . $this->php_ext . '?i=ucp_profile&mode=reg_details'))
		);

		$mail = $this->send_verification($user_row, $email, bin2hex(random_bytes(16)));

		return ['pm' => $pm, 'email' => $mail, 'lang' => $this->user_lang($user_row)];
	}

	/**
	 * A language object in the member's own language.
	 *
	 * The fallback is set to English rather than to the board language: a
	 * French member on an Italian board is better served by English than by
	 * Italian when French is missing from the extension.
	 *
	 * @param string $iso Language of the member, from his profile
	 * @return \phpbb\language\language
	 */
	protected function language_for($iso)
	{
		$iso = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $iso));

		if ($iso === '')
		{
			$iso = (string) $this->config['default_lang'];
		}

		if (!isset($this->languages[$iso]))
		{
			$language = new \phpbb\language\language($this->loader);
			$language->set_default_language('en');
			$language->set_user_language($iso, true);
			$language->add_lang('common', 'salvocortesiano/mailhealth');

			$this->languages[$iso] = $language;
		}

		return $this->languages[$iso];
	}

	/**
	 * Language of a member, straight from his profile.
	 */
	protected function user_lang(array $user_row)
	{
		return !empty($user_row['user_lang']) ? $user_row['user_lang'] : (string) $this->config['default_lang'];
	}

	protected function board_url($path)
	{
		return generate_board_url() . '/' . $path;
	}
}
