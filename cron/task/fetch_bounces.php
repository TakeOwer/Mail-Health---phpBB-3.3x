<?php
/**
 *
 * Mail Health. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\mailhealth\cron\task;

class fetch_bounces extends \phpbb\cron\task\base
{
	/** @var \phpbb\config\config */
	protected $config;

	/** @var \salvocortesiano\mailhealth\service\imap_client */
	protected $imap;

	/** @var \salvocortesiano\mailhealth\service\dsn_parser */
	protected $parser;

	/** @var \salvocortesiano\mailhealth\service\manager */
	protected $manager;

	/** @var array What the last run did, for the manual run in the ACP */
	protected $last_stats = [];

	public function __construct(
		\phpbb\config\config $config,
		\salvocortesiano\mailhealth\service\imap_client $imap,
		\salvocortesiano\mailhealth\service\dsn_parser $parser,
		\salvocortesiano\mailhealth\service\manager $manager
	)
	{
		$this->config = $config;
		$this->imap = $imap;
		$this->parser = $parser;
		$this->manager = $manager;
	}

	/** Name phpBB uses in the cron URL: must never be empty */
	const NAME = 'salvocortesiano.mailhealth.cron.fetch_bounces';

	/**
	 * phpBB builds the cron link in every page footer from this name. An
	 * empty name makes that link impossible to build and the page dies with
	 * a fatal error, so the name is declared here as well as through
	 * set_name() in services.yml: either one alone is enough.
	 *
	 * @return string
	 */
	public function get_name()
	{
		$name = parent::get_name();

		return ($name !== null && $name !== '') ? $name : self::NAME;
	}

	/**
	 * Seconds one run may spend reading the mailbox. Board cron runs inside
	 * a visitor's request: it must stay short, and well inside the host's
	 * max_execution_time. What is not done now is done at the next run.
	 */
	public static function time_budget()
	{
		$limit = (int) @ini_get('max_execution_time');
		$budget = 10;

		if ($limit > 0)
		{
			$budget = (int) min($budget, max(3, floor($limit / 3)));
		}

		return $budget;
	}

	public function run()
	{
		$started = microtime(true);
		$deadline = $started + self::time_budget();

		// Recorded first: if anything below dies, the task is not retried on
		// every page view but waits for the normal interval.
		$this->config->set('mailhealth_last_run', time(), false);
		$this->config->set('mailhealth_run_started', time(), false);

		$stats = [
			'connected'	=> false,
			'error'		=> ['', ''],
			'examined'	=> 0,
			'bounces'	=> 0,
			'ignored'	=> 0,
			'stopped'	=> false,
			'seconds'	=> 0,
		];

		if ($this->imap->connect())
		{
			$stats['connected'] = true;

			foreach ($this->imap->new_uids((int) $this->config['mailhealth_batch_size']) as $uid)
			{
				if (microtime(true) >= $deadline)
				{
					$stats['stopped'] = true;
					break;
				}

				// One message at a time, and ordinary mail is judged on its
				// headers only: memory use stays flat whatever the mailbox.
				$raw = $this->imap->read_candidate($uid, $this->parser);
				$bounce = ($raw === false) ? false : $this->parser->parse($raw);
				unset($raw);

				$stats['examined']++;

				if ($bounce === false)
				{
					// Not a bounce: left exactly where it is.
					$stats['ignored']++;
				}
				else
				{
					$this->manager->record($bounce);
					$stats['bounces']++;

					if ($this->config['mailhealth_imap_delete'])
					{
						$this->imap->mark_deleted($uid);
					}
				}

				$this->imap->mark_examined($uid);
			}

			$this->imap->close((bool) $this->config['mailhealth_imap_delete']);
			$this->imap->commit_position();
		}
		else
		{
			$stats['error'] = $this->imap->get_last_error();
		}

		// These run even when the mailbox is unreachable: a member who has
		// changed his address should get his notifications back regardless.
		$this->manager->sync_states();
		$this->manager->check_alert();
		$this->manager->prune();

		$stats['seconds'] = round(microtime(true) - $started, 1);

		$this->config->set('mailhealth_run_finished', time(), false);
		$this->config->set('mailhealth_run_seconds', $stats['seconds'], false);

		$this->last_stats = $stats;
	}

	/**
	 * @return array ['connected', 'error' => [key, detail], 'examined', 'bounces', 'ignored', 'stopped', 'seconds']
	 */
	public function get_last_stats()
	{
		return $this->last_stats;
	}

	/**
	 * Waits for the migrations: files uploaded over an enabled extension run
	 * before phpBB has created the new tables, and the cron must not touch
	 * them until then.
	 */
	public function is_runnable()
	{
		return (bool) $this->config['mailhealth_enabled']
			&& version_compare((string) $this->config['mailhealth_version'], \salvocortesiano\mailhealth\service\diagnostics::SCHEMA_VERSION, '>=');
	}

	public function should_run()
	{
		return $this->config['mailhealth_last_run'] < time() - (int) $this->config['mailhealth_cron_interval'];
	}
}
