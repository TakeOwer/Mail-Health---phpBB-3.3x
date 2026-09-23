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
 * Mailbox access with two interchangeable transports:
 *
 *   - ext-imap, when the host provides it;
 *   - imap_native, a plain socket client, when it does not. ext-imap left
 *     the PHP core in 8.4, so this is what most boards will end up using.
 *
 * The choice is automatic unless the administrator forces one in the ACP.
 * The rest of the extension never knows which one is running.
 */
class imap_client
{
	const TRANSPORT_AUTO	= 0;
	const TRANSPORT_EXT		= 1;
	const TRANSPORT_NATIVE	= 2;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var crypto */
	protected $crypto;

	/** @var resource|\IMAP\Connection|false ext-imap stream */
	protected $stream = false;

	/** @var imap_native|null */
	protected $native = null;

	/** @var array [language key, technical detail] */
	protected $last_error = ['', ''];

	/** @var string Mailbox string for ext-imap, kept for imap_status() */
	protected $mailbox = '';

	/** @var int Highest UID examined so far in this run */
	protected $pending_uid = 0;

	/** @var int UIDVALIDITY seen by new_uids() */
	protected $pending_validity = 0;

	public function __construct(\phpbb\config\config $config, crypto $crypto)
	{
		$this->config = $config;
		$this->crypto = $crypto;
	}

	public static function ext_available()
	{
		return function_exists('imap_open');
	}

	/**
	 * Which transport will be used, given the setting and the server.
	 *
	 * @return int TRANSPORT_EXT, TRANSPORT_NATIVE, or 0 when none is possible
	 */
	public function get_transport()
	{
		$wanted = (int) $this->config['mailhealth_imap_transport'];

		if ($wanted === self::TRANSPORT_EXT)
		{
			return self::ext_available() ? self::TRANSPORT_EXT : 0;
		}

		if ($wanted === self::TRANSPORT_NATIVE)
		{
			return imap_native::is_supported() ? self::TRANSPORT_NATIVE : 0;
		}

		if (self::ext_available())
		{
			return self::TRANSPORT_EXT;
		}

		return imap_native::is_supported() ? self::TRANSPORT_NATIVE : 0;
	}

	public function get_transport_name()
	{
		switch ($this->get_transport())
		{
			case self::TRANSPORT_EXT:
				return 'ext-imap';
			case self::TRANSPORT_NATIVE:
				return 'socket';
		}

		return '';
	}

	public function is_available()
	{
		return $this->get_transport() !== 0;
	}

	/**
	 * @return array [language key, technical detail]
	 */
	public function get_last_error()
	{
		return $this->last_error;
	}

	/**
	 * The last error as a sentence in the administrator's language. The
	 * server's own words, when there are any, go inside the sentence as a
	 * technical detail - never on their own.
	 */
	public function get_error_message(\phpbb\language\language $language)
	{
		return self::format_error($this->last_error, $language);
	}

	/**
	 * @param array $error [language key, technical detail]
	 */
	public static function format_error(array $error, \phpbb\language\language $language)
	{
		list($key, $detail) = $error + ['', ''];

		if ($key === '' || !$language->is_set($key))
		{
			return $language->lang('MAILHEALTH_IMAP_UNKNOWN_ERROR');
		}

		return $language->lang($key, $detail);
	}

	/**
	 * @return bool
	 */
	public function connect()
	{
		$transport = $this->get_transport();

		if ($transport === 0)
		{
			$this->last_error = ['MAILHEALTH_ERROR_NO_IMAP', ''];
			return false;
		}

		$host = (string) $this->config['mailhealth_imap_host'];
		$port = (int) $this->config['mailhealth_imap_port'];
		$folder = (string) $this->config['mailhealth_imap_folder'];
		$user = (string) $this->config['mailhealth_imap_user'];
		$security = (int) $this->config['mailhealth_imap_security'];

		if ($host === '' || $port === 0)
		{
			$this->last_error = ['MAILHEALTH_ERROR_NOT_CONFIGURED', ''];
			return false;
		}

		// The password is decrypted here and nowhere else, kept in a local
		// variable and wiped straight after the connection attempt.
		$password = $this->crypto->decrypt((string) $this->config['mailhealth_imap_pass']);

		if ($password === false)
		{
			$this->last_error = [$this->crypto->get_error(), ''];
			return false;
		}

		if ($transport === self::TRANSPORT_NATIVE)
		{
			$this->native = new imap_native();
			$ok = $this->native->connect($host, $port, $security, $user, $password, $folder, 15);

			if (!$ok)
			{
				$this->last_error = $this->native->get_error();
				$this->native = null;
			}
		}
		else
		{
			$flags = [
				imap_native::SECURITY_NONE		=> '/imap/notls',
				imap_native::SECURITY_SSL		=> '/imap/ssl',
				imap_native::SECURITY_STARTTLS	=> '/imap/tls',
			];
			$mailbox = '{' . $host . ':' . $port . (isset($flags[$security]) ? $flags[$security] : '/imap/ssl') . '}' . ($folder !== '' ? $folder : 'INBOX');
			$this->mailbox = $mailbox;

			// Suppressed because imap_open emits warnings on every failure;
			// the error is read back explicitly below.
			imap_timeout(IMAP_OPENTIMEOUT, 15);
			imap_timeout(IMAP_READTIMEOUT, 15);
			$this->stream = @imap_open($mailbox, $user, $password, 0, 1);
			$ok = ($this->stream !== false);

			if (!$ok)
			{
				$server = trim((string) imap_last_error());
				$this->last_error = ($server !== '') ? ['MAILHEALTH_IMAP_SERVER_ERROR', $server] : ['MAILHEALTH_IMAP_CONNECT_FAILED', '-'];
			}

			imap_errors();
		}

		$this->wipe($password);

		return $ok;
	}

	/** Most of a delivery report that is ever needed */
	const PARTIAL_BYTES = 262144;

	/**
	 * UIDs of up to $limit messages that arrived after the last run, oldest
	 * first. Nothing is downloaded here.
	 *
	 * The position only moves forward through mark_examined() and is only
	 * saved by commit_position(): a run that stops half way simply carries
	 * on from the last message it actually examined.
	 *
	 * If the server reports a new UIDVALIDITY (the folder was recreated or
	 * the mailbox moved), UIDs are no longer comparable and reading starts
	 * again from the beginning.
	 *
	 * @param int $limit
	 * @return array
	 */
	public function new_uids($limit)
	{
		$limit = max(1, (int) $limit);
		$last = (int) $this->config['mailhealth_imap_last_uid'];
		$known_validity = (int) $this->config['mailhealth_imap_uidvalidity'];
		$uids = [];
		$validity = 0;

		if ($this->native !== null)
		{
			$validity = $this->native->get_uidvalidity();
			$last = ($validity !== $known_validity) ? 0 : $last;
			$uids = $this->native->uids_after($last);
		}
		else if ($this->stream !== false)
		{
			$status = @imap_status($this->stream, $this->mailbox, SA_UIDVALIDITY);
			$validity = ($status && isset($status->uidvalidity)) ? (int) $status->uidvalidity : 0;
			$last = ($validity !== $known_validity) ? 0 : $last;

			foreach ((array) imap_search($this->stream, 'ALL', SE_UID) as $uid)
			{
				if ((int) $uid > $last)
				{
					$uids[] = (int) $uid;
				}
			}

			sort($uids);
		}

		$this->pending_validity = $validity;
		$this->pending_uid = $last;

		return array_slice($uids, 0, $limit);
	}

	/**
	 * Returns the message only if its headers say it is a bounce, and then
	 * only its first PARTIAL_BYTES. Everything else - ordinary mail with its
	 * attachments - is judged on the header block and never downloaded.
	 *
	 * @return string|false
	 */
	public function read_candidate($uid, dsn_parser $parser)
	{
		if ($this->native !== null)
		{
			$head = $this->native->fetch_header_uid($uid);

			if ($head === false || !$parser->is_candidate($head['header']))
			{
				return false;
			}

			return $this->native->fetch_partial_uid($uid, self::PARTIAL_BYTES);
		}

		if ($this->stream === false)
		{
			return false;
		}

		$header = imap_fetchheader($this->stream, $uid, FT_UID);

		if ($header === false || !$parser->is_candidate($header))
		{
			return false;
		}

		$overview = @imap_fetch_overview($this->stream, (string) (int) $uid, FT_UID);
		$size = (!empty($overview[0]) && isset($overview[0]->size)) ? (int) $overview[0]->size : 0;

		if ($size > 0 && $size <= self::PARTIAL_BYTES)
		{
			// FT_PEEK leaves the \Seen flag alone, like BODY.PEEK does natively
			return $header . "\r\n" . (string) imap_body($this->stream, $uid, FT_UID | FT_PEEK);
		}

		// Large report (the original message attached in full): the human
		// text and the machine readable part are the first two parts.
		return $header . "\r\n"
			. (string) imap_fetchbody($this->stream, $uid, '1', FT_UID | FT_PEEK) . "\r\n"
			. (string) imap_fetchbody($this->stream, $uid, '2', FT_UID | FT_PEEK);
	}

	/**
	 * Moves the reading position past this message.
	 */
	public function mark_examined($uid)
	{
		$this->pending_uid = max($this->pending_uid, (int) $uid);
	}

	/**
	 * Saves how far reading got. Called once the fetched messages have all
	 * been examined.
	 */
	public function commit_position()
	{
		$this->config->set('mailhealth_imap_last_uid', $this->pending_uid, false);
		$this->config->set('mailhealth_imap_uidvalidity', $this->pending_validity, false);
	}

	/**
	 * Starts reading from the first message again, e.g. after changing the
	 * mailbox in the settings.
	 */
	public function reset_position()
	{
		$this->config->set('mailhealth_imap_last_uid', 0, false);
		$this->config->set('mailhealth_imap_uidvalidity', 0, false);
	}

	/**
	 * Flags a message for deletion, by UID. Nothing is removed until close().
	 */
	public function mark_deleted($uid)
	{
		if ($this->native !== null)
		{
			$this->native->delete_uid((int) $uid);
		}
		else if ($this->stream !== false)
		{
			imap_delete($this->stream, (string) (int) $uid, FT_UID);
		}
	}

	public function close($expunge = true)
	{
		if ($this->native !== null)
		{
			if ($expunge)
			{
				$this->native->expunge();
			}

			$this->native->logout();
			$this->native = null;
			return;
		}

		if ($this->stream === false)
		{
			return;
		}

		if ($expunge)
		{
			imap_expunge($this->stream);
		}

		imap_close($this->stream);
		imap_errors();
		$this->stream = false;
	}

	/**
	 * Tries the usual combinations of port and security against a server,
	 * without logging in, most secure first. Works with the built-in client
	 * whatever transport is selected, since it only needs a socket.
	 *
	 * @param string $host
	 * @return array Each: ['port' => int, 'security' => int, 'ok' => bool, 'error' => [key, detail]]
	 */
	public function detect_settings($host)
	{
		$results = [];

		if (!imap_native::is_supported() || trim($host) === '')
		{
			return $results;
		}

		$candidates = [
			[993, imap_native::SECURITY_SSL],
			[143, imap_native::SECURITY_STARTTLS],
			[143, imap_native::SECURITY_NONE],
		];

		foreach ($candidates as $candidate)
		{
			list($port, $security) = $candidate;

			$probe = new imap_native();
			$ok = $probe->probe(trim($host), $port, $security, 6);

			$results[] = [
				'port'		=> $port,
				'security'	=> $security,
				'ok'		=> $ok,
				'error'		=> $ok ? ['', ''] : $probe->get_error(),
			];

			// Stop at the first secure option that works: no reason to try
			// anything weaker, and each attempt can take a few seconds.
			if ($ok)
			{
				break;
			}
		}

		return $results;
	}

	protected function wipe(&$value)
	{
		if (!is_string($value))
		{
			return;
		}

		if (function_exists('sodium_memzero'))
		{
			sodium_memzero($value);
		}
		else
		{
			$value = str_repeat("\0", strlen($value));
		}
	}
}
