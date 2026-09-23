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
 * Minimal IMAP4rev1 client written on plain sockets.
 *
 * ext-imap was unbundled from PHP in 8.4 and many hosts no longer ship it, so
 * this covers exactly what Mail Health needs and nothing more: log in, open a
 * folder, read whole messages, flag them deleted, expunge, log out.
 *
 * Messages are addressed by sequence number, like ext-imap does, and deleted
 * messages keep their number until EXPUNGE - so the two transports behave
 * the same from the caller's point of view.
 */
class imap_native
{
	/** @var resource|false */
	protected $fp = false;

	/** @var int */
	protected $tag = 0;

	/** @var array [language key, technical detail from the server] */
	protected $error = ['', ''];

	/** @var int Messages in the selected folder */
	protected $exists = 0;

	/** @var int UIDVALIDITY of the selected folder, 0 when not reported */
	protected $uidvalidity = 0;

	/** Connection security */
	const SECURITY_NONE		= 0;
	const SECURITY_SSL		= 1;
	const SECURITY_STARTTLS	= 2;

	public static function is_supported()
	{
		return function_exists('stream_socket_client');
	}

	/**
	 * @return array [language key, technical detail]
	 */
	public function get_error()
	{
		return $this->error;
	}

	/**
	 * Opens the connection and brings it to the point where LOGIN can be
	 * sent: TCP or TLS socket, greeting read, STARTTLS done when asked for.
	 *
	 * @return bool
	 */
	protected function open($host, $port, $security, $timeout)
	{
		$context = stream_context_create(['ssl' => [
			'verify_peer'		=> true,
			'verify_peer_name'	=> true,
			'SNI_enabled'		=> true,
			'peer_name'			=> $host,
		]]);

		$remote = (((int) $security === self::SECURITY_SSL) ? 'ssl://' : 'tcp://') . $host . ':' . (int) $port;
		$errno = 0;
		$errstr = '';

		$this->fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

		if ($this->fp === false)
		{
			// With ssl:// an error number of 0 means the TCP connection was
			// made and the TLS handshake failed: the server is there, it just
			// does not speak SSL/TLS on this port (or its certificate does not
			// match). Telling "unreachable" here would send the admin looking
			// at the host's firewall for nothing.
			if ((int) $security === self::SECURITY_SSL && (int) $errno === 0)
			{
				$this->error = ['MAILHEALTH_IMAP_SSL_HANDSHAKE', ''];
			}
			else
			{
				$this->error = ['MAILHEALTH_IMAP_CONNECT_FAILED', trim($errstr) !== '' ? trim($errstr) : (string) $errno];
			}

			return false;
		}

		stream_set_timeout($this->fp, $timeout);

		$greeting = $this->read_line();

		if ($greeting === false || strpos($greeting, '* OK') !== 0)
		{
			$this->error = ($greeting === false) ? ['MAILHEALTH_IMAP_NO_ANSWER', ''] : ['MAILHEALTH_IMAP_BAD_GREETING', trim($greeting)];
			$this->disconnect();
			return false;
		}

		if ((int) $security === self::SECURITY_STARTTLS)
		{
			$response = $this->command(['STARTTLS']);

			if (!$response['ok'])
			{
				$this->error = $response['lost'] ? ['MAILHEALTH_IMAP_CONNECTION_LOST', ''] : ['MAILHEALTH_IMAP_NO_STARTTLS', $response['text']];
				$this->disconnect();
				return false;
			}

			// Same approach phpBB uses for SMTP STARTTLS: blocking mode for
			// the handshake, then back to how the stream was.
			$meta = stream_get_meta_data($this->fp);
			$secured = false;

			if (function_exists('stream_socket_enable_crypto') && stream_set_blocking($this->fp, true))
			{
				$secured = @stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
				stream_set_blocking($this->fp, !empty($meta['blocked']));
			}

			if (!$secured)
			{
				$this->error = ['MAILHEALTH_IMAP_TLS_FAILED', ''];
				$this->disconnect();
				return false;
			}
		}

		return true;
	}

	/**
	 * Only checks that a mail server answers with the given port and
	 * security, without logging in. Used to find the right settings.
	 *
	 * @return bool
	 */
	public function probe($host, $port, $security, $timeout = 6)
	{
		$ok = $this->open($host, $port, $security, $timeout);

		if ($ok)
		{
			$this->logout();
		}

		return $ok;
	}

	/**
	 * @param int $security One of the SECURITY_* constants
	 * @return bool
	 */
	public function connect($host, $port, $security, $user, $password, $folder, $timeout = 20)
	{
		if (!$this->open($host, $port, $security, $timeout))
		{
			return false;
		}

		$response = $this->command(['LOGIN', $this->astring($user), $this->astring($password)]);

		if (!$response['ok'])
		{
			$this->error = $response['lost'] ? ['MAILHEALTH_IMAP_CONNECTION_LOST', ''] : ['MAILHEALTH_IMAP_LOGIN_FAILED', $response['text']];
			$this->disconnect();
			return false;
		}

		$response = $this->command(['SELECT', $this->astring($folder !== '' ? $folder : 'INBOX')]);

		if (!$response['ok'])
		{
			$this->error = $response['lost'] ? ['MAILHEALTH_IMAP_CONNECTION_LOST', ''] : ['MAILHEALTH_IMAP_FOLDER_FAILED', $response['text']];
			$this->logout();
			return false;
		}

		foreach ($response['lines'] as $line)
		{
			if (preg_match('/^\* (\d+) EXISTS/i', $line, $m))
			{
				$this->exists = (int) $m[1];
			}

			if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $m))
			{
				$this->uidvalidity = (int) $m[1];
			}
		}

		return true;
	}

	public function count()
	{
		return $this->exists;
	}

	public function get_uidvalidity()
	{
		return $this->uidvalidity;
	}

	/**
	 * UIDs above $after, lowest first. UIDs never change for a message and
	 * only grow, so remembering the highest one examined is enough to read
	 * only what arrived since.
	 *
	 * @return array
	 */
	public function uids_after($after)
	{
		$after = max(0, (int) $after);

		// "n:*" always includes the highest UID even when it is below n,
		// hence the filter afterwards.
		$response = $this->command(['UID SEARCH UID ' . ($after + 1) . ':*']);
		$uids = [];

		if (!$response['ok'])
		{
			return $uids;
		}

		foreach ($response['lines'] as $line)
		{
			if (preg_match('/^\* SEARCH\b(.*)$/i', $line, $m))
			{
				foreach (preg_split('/\s+/', trim($m[1])) as $uid)
				{
					if (ctype_digit($uid) && (int) $uid > $after)
					{
						$uids[] = (int) $uid;
					}
				}
			}
		}

		sort($uids);

		return $uids;
	}

	/**
	 * Header block and total size of a message, without downloading its body.
	 *
	 * @return array|false ['header' => string, 'size' => int]
	 */
	public function fetch_header_uid($uid)
	{
		$response = $this->command(['UID FETCH ' . (int) $uid . ' (RFC822.SIZE BODY.PEEK[HEADER])']);

		if (!$response['ok'] || empty($response['literals']))
		{
			return false;
		}

		$size = 0;

		foreach (array_merge($response['heads'], $response['lines']) as $line)
		{
			if (preg_match('/RFC822\.SIZE (\d+)/i', $line, $m))
			{
				$size = (int) $m[1];
			}
		}

		return ['header' => $response['literals'][0], 'size' => $size];
	}

	/**
	 * The first $max bytes of a message. Delivery reports put everything
	 * that matters at the top; the returned copy of the original message,
	 * attachments included, is never needed.
	 *
	 * @return string|false
	 */
	public function fetch_partial_uid($uid, $max)
	{
		$response = $this->command(['UID FETCH ' . (int) $uid . ' BODY.PEEK[]<0.' . max(1024, (int) $max) . '>']);

		if (!$response['ok'] || empty($response['literals']))
		{
			return false;
		}

		return $response['literals'][0];
	}

	public function delete_uid($uid)
	{
		$this->command(['UID STORE ' . (int) $uid . ' +FLAGS.SILENT (\\Deleted)']);
	}



	public function expunge()
	{
		$this->command('EXPUNGE');
	}

	public function logout()
	{
		if ($this->fp !== false)
		{
			$this->command('LOGOUT');
		}

		$this->disconnect();
	}

	protected function disconnect()
	{
		if ($this->fp !== false)
		{
			@fclose($this->fp);
		}

		$this->fp = false;
	}

	/**
	 * Sends one command and collects the whole response, literals included.
	 *
	 * @param array $parts Command pieces: plain strings are sent as they are,
	 *                     ['literal' => value] pieces are sent as IMAP
	 *                     literals, waiting for the server's "+" first.
	 * @return array ['ok' => bool, 'text' => string, 'lines' => array, 'literals' => array]
	 */
	protected function command($parts)
	{
		$result = ['ok' => false, 'lost' => false, 'text' => '', 'lines' => [], 'literals' => [], 'heads' => []];

		if ($this->fp === false)
		{
			return $result;
		}

		$tag = 'MH' . (++$this->tag);

		if (!$this->send($tag, is_array($parts) ? $parts : [$parts]))
		{
			$result['lost'] = true;
			return $result;
		}

		while (true)
		{
			$line = $this->read_line();

			if ($line === false)
			{
				$result['lost'] = true;
				$this->disconnect();
				return $result;
			}

			// A literal: {n} at the end of the line, then exactly n bytes.
			while (preg_match('/\{(\d+)\}\r\n$/', $line, $m))
			{
				$literal = $this->read_bytes((int) $m[1]);

				if ($literal === false)
				{
					$result['lost'] = true;
					$this->disconnect();
					return $result;
				}

				$result['literals'][] = $literal;
				$result['heads'][] = $line;

				$rest = $this->read_line();
				$line = ($rest === false) ? "\r\n" : $rest;
			}

			if (strpos($line, $tag . ' ') === 0)
			{
				$status = substr($line, strlen($tag) + 1);
				$result['ok'] = (stripos($status, 'OK') === 0);
				$result['text'] = trim($status);
				return $result;
			}

			$result['lines'][] = rtrim($line, "\r\n");
		}
	}

	/**
	 * Writes a command. Before each literal the line so far is flushed with
	 * {n}CRLF and the server must answer with a "+" continuation.
	 */
	protected function send($tag, array $parts)
	{
		$buffer = $tag;

		foreach ($parts as $part)
		{
			if (is_array($part))
			{
				$value = (string) $part['literal'];

				if (@fwrite($this->fp, $buffer . ' {' . strlen($value) . "}\r\n") === false)
				{
					return false;
				}

				$continuation = $this->read_line();

				if ($continuation === false || strpos($continuation, '+') !== 0)
				{
					return false;
				}

				$buffer = $value;
			}
			else
			{
				$buffer .= ' ' . $part;
			}
		}

		return @fwrite($this->fp, $buffer . "\r\n") !== false;
	}

	/**
	 * Quoted string when every byte is printable ASCII, literal otherwise.
	 *
	 * @return string|array
	 */
	protected function astring($value)
	{
		$value = (string) $value;

		if (preg_match('/^[\x20-\x7e]*$/', $value))
		{
			return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
		}

		return ['literal' => $value];
	}

	/**
	 * @return string|false Line including its CRLF
	 */
	protected function read_line()
	{
		$line = @fgets($this->fp);

		if ($line === false)
		{
			return false;
		}

		$meta = stream_get_meta_data($this->fp);

		return empty($meta['timed_out']) ? $line : false;
	}

	/**
	 * @return string|false
	 */
	protected function read_bytes($length)
	{
		$data = '';

		while (strlen($data) < $length)
		{
			$chunk = @fread($this->fp, min(8192, $length - strlen($data)));

			if ($chunk === false || $chunk === '')
			{
				$meta = stream_get_meta_data($this->fp);

				if (!empty($meta['timed_out']) || feof($this->fp))
				{
					return false;
				}

				continue;
			}

			$data .= $chunk;
		}

		return $data;
	}
}
