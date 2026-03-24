<?php

namespace ManiaLivePlugins\skorlok\tacupmanager;

use ManiaLive\Event\Dispatcher;
use ManiaLivePlugins\eXpansion\Core\DataAccess;
use ManiaLivePlugins\eXpansion\Core\types\config\Variable;
use ManiaLivePlugins\eXpansion\Core\types\ExpPlugin;
use ManiaLivePlugins\eXpansion\Helpers\Formatting;
use ManiaLivePlugins\eXpansion\LocalRecords\Events\Event as LocalEvent;

class tacupmanager extends ExpPlugin
{
	private $config;
	private $configReloaded = false;
	private $cacheDiscordResults = array();
	private $dataAccess;
	private $headers;
	private $lastSend = 0;
	private $lastDiscordSend = 0;
	private $lastDataDiscordSent = null;
	private $needReload = false;
	private $needDiscordSend = false;
	private $rateLimitDiscord = 0;
	
    public function eXpOnReady()
    {
		/** @var Config $config */
		$this->config = Config::getInstance();
		$this->enableDatabase();
		$this->enableTickerEvent();
		$this->dataAccess = DataAccess::getInstance();
		$this->headers = array("Authorization: Bot " . $this->config->discordToken, "Content-Type: application/json; charset=utf-8");
        Dispatcher::register(LocalEvent::getClass(), $this);

		$this->needReload = true;
    }

	public function onSettingsChanged(Variable $var)
    {
		/** @var Config $config */
        $this->config = Config::getInstance();
		$this->headers = array("Authorization: Bot " . $this->config->discordToken, "Content-Type: application/json; charset=utf-8");
		$this->configReloaded = true;
    }

	public function onTick()
	{
		if ($this->configReloaded) {
			$this->configReloaded = false;
			$this->sendResults();
		}

		if ($this->needReload && time() - $this->lastSend > 5) {
			$this->needReload = false;
			$this->lastSend = time();
			$this->sendResults();
		}

		if ($this->needDiscordSend && time() - $this->lastDiscordSend > 120) {
			$this->needDiscordSend = false;
			$this->lastDiscordSend = time();
			$this->prepareDiscordResults();
		}

		if ($this->rateLimitDiscord > 0 && (time() - $this->lastDiscordSend) > $this->rateLimitDiscord) {
			$this->dataAccess->httpCurl($this->lastDataDiscordSent[0], $this->lastDataDiscordSent[1], $this->lastDataDiscordSent[2], $this->lastDataDiscordSent[3]);
			$this->lastDiscordSend = time();
			$this->rateLimitDiscord = 0;
			return;
		}
	}

    public function onRecordsLoaded($data)
    {
    }

    public function onUpdateRecords($data)
    {
    }

    public function onNewRecord($data)
    {
    }

    public function onRecordPlayerFinished($login)
    {
    }

    public function onRecordDeleted($removedRecord, $records)
    {
    }

    public function onPersonalBestRecord($data)
    {
		$this->needReload = true;
    }

	private function sendResults() {
		// ensure file exists
		if (!file_exists($this->config->outputFileName)) {
			if (!is_dir(dirname($this->config->outputFileName))) {
				mkdir(dirname($this->config->outputFileName), 0777, true);
			}
			file_put_contents($this->config->outputFileName, "");
		}
		// ensure file is writable
		if (!is_writable($this->config->outputFileName)) {
			$this->console("Output file is not writable: " . $this->config->outputFileName);
			return;
		}

		$template = file_get_contents($this->config->templateFileName);
		if (!$template) {
			$this->console("Failed to read template file: " . $this->config->templateFileName);
			return;
		}

		$scores = $this->computeScores();

		$out = "";
		if ($this->config->buildWrTable > 0) {
			$out .= $this->buildWrTable($scores, $this->config->buildWrTable);
		}
		if ($this->config->buildPositionTable) {
			if ($this->config->replaysURL) {
				if (substr($this->config->replaysURL, -1) != "/") {
					$replayURL = $this->config->replaysURL . "/";
				} else {
					$replayURL = $this->config->replaysURL;
				}
			} else {
				$replayURL = null;
			}
			$out .= $this->buildHtmlTable($scores, $this->config->displayOverallTimeInsteadOfPosition, $replayURL);
		}

		$template = str_replace("%content%", $out, $template);
		file_put_contents($this->config->outputFileName, $template);

		$this->console("Results exported to " . $this->config->outputFileName);

		if ($this->config->discordToken && $this->config->discordChannelId) {
			$this->cacheDiscordResults = $this->prettyPrintForDiscord($scores);
			$this->needDiscordSend = true;
		}
	}

	private function prepareDiscordResults() {
		$url = 'https://discord.com/api/channels/' . $this->config->discordChannelId . '/messages?limit=100';

        $options = array(CURLOPT_CONNECTTIMEOUT => 25, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $this->headers);
        $this->dataAccess->httpCurl($url, array($this, "sendDiscordResults"), array("action" => "getMessages"), $options);
	}

	public function sendDiscordResults($job, $jobData) {
		$info = $job->getCurlInfo();
        $code = $info['http_code'];
        $data = $job->getResponse();
		$additionalData = $job->__additionalData;

		$action = $additionalData['action'];
		$needSend = false;
		$method = "POST";
		$url = "";

		if (substr($code, 0, 1) != 2) {
            $this->console("Error on discord request, " . $action . " : " . $code);
			if ($data) {
				$json = json_decode($data, true);
				if ($code == 429) {
					$this->rateLimitDiscord = $json['retry_after'];
					return;
				}
				$this->console(print_r($json, true));
				return;
			}
        }

		if ($data) {
            $json = json_decode($data, true);
			if (!is_array($json)) {
				$this->console("Invalid Discord response");
				$this->lastDataDiscordSent = null;
				return;
			}
        }

		if ($action == "getMessages") {
			if (!$json) {
				$this->console("Failed to get messages from Discord");
				$this->lastDataDiscordSent = null;
				return;
			}
			if (count($json) > 1) {
				$url = 'https://discord.com/api/channels/' . $this->config->discordChannelId . '/messages/bulk-delete';

				$messagesToDelete = array();
				$now = time();
				foreach ($json as $message) {
					$timestamp = (strtotime($message['timestamp']));
					if ($now - $timestamp < 14 * 24 * 60 * 60) {
						$messagesToDelete[] = $message['id'];
					}
				}

				$messagesToDelete = array_slice($messagesToDelete, 0, 100);

				if (count($messagesToDelete) >= 2) {
					$postData = array("messages" => $messagesToDelete);
					$nextAction = "deleteMessages";
				} elseif (count($messagesToDelete) == 1) {
					$url = 'https://discord.com/api/channels/' . $this->config->discordChannelId . '/messages/' . $messagesToDelete[0];
					$method = "DELETE";
					$nextAction = "deleteMessages";
				} else {
					$needSend = true;
					$this->lastDataDiscordSent = null;
				}
			} else if (count($json) == 1) {
				$url = 'https://discord.com/api/channels/' . $this->config->discordChannelId . '/messages/' . $json[0]['id'];

				$postData = array();
				$nextAction = "deleteMessages";
				$method = "DELETE";
			} else {
				// no messages, skipping action and sending message directly
				if (count($this->cacheDiscordResults) > 0 ) {
					$needSend = true;
				} else {
					$this->lastDataDiscordSent = null;
					return;
				}
			}
		} else if ($action == "deleteMessages" || $action == "sendMessage") {
			if (count($this->cacheDiscordResults) > 0) {
				$needSend = true;
			} else {
				$this->lastDataDiscordSent = null;
				return;
			}
		} else {
			if (!$needSend) {
				$this->lastDataDiscordSent = null;
				return;
			}
		}

		if ($needSend) {
			$url = 'https://discord.com/api/channels/' . $this->config->discordChannelId . '/messages';

			$postData = array("content" => array_shift($this->cacheDiscordResults));
			$nextAction = "sendMessage";
		}

        if ($method == "POST") {
			$options = array(CURLOPT_CONNECTTIMEOUT => 25, CURLOPT_TIMEOUT => 30, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($postData), CURLOPT_HTTPHEADER => $this->headers);
		} else {
			$options = array(CURLOPT_CONNECTTIMEOUT => 25, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $this->headers);
		}
        $this->dataAccess->httpCurl($url, array($this, "sendDiscordResults"), array("action" => $nextAction), $options);

		$this->lastDataDiscordSent = array($url, array($this, "sendDiscordResults"), array("action" => $nextAction), $options);
	}
	
	private function formatTime($t) {
		$minutes = floor($t / 60000);
		$t %= 60000;

		$seconds = floor($t / 1000);
		$t %= 1000;

		return sprintf('%02d:%02d.%03d', $minutes, $seconds, $t);
	}
	
	private function getSqlResults() {
		$maps = $this->storage->maps;

		$uids = "";
		foreach ($maps as $map) {
			$uids .= $this->db->quote($map->uId) . ",";
		}
		if ($uids) {
			$uids = 'WHERE `record_challengeuid` IN (' . trim($uids, ",") . ')';
		}

		// SQL

		$req = "SELECT `record_challengeuid`, `record_playerlogin`, `record_score`, `player_nickname`, `rank`
			FROM (SELECT *, row_number() over (partition by `record_challengeuid`, `record_nbLaps`, `score_type` order by `record_score` ASC, `record_date` ASC) as `rank` FROM `exp_records`) temp
			INNER JOIN exp_players on `record_playerlogin` = `player_login`
			" . $uids . ";";

		/*
		[record_challengeuid] => 0qAsXvoCve7RCLRGYnlnjI8YGIb
		[record_playerlogin] => skorlok
		[record_score] => 55562
		[player_nickname] => $58eM$fffD" ▼$C96$iDirtLess$z$C ► $n$0Θ$FFFǤ$FЯ $z$i$s$fS$FFFkorlok
		[rank] => 1
		*/
		$data = $this->db->execute($req)->fetchArrayOfObject();
		return $data;
	}
	
	private function computeScores($colorNicks = true) {
		$scores = $this->getSqlResults();

		$scoreFinal = array();
		$rankLogins = array();
		$playerNicknames = array();

		$posFinal = array(); // Used to build WR table
		$timeLogins = array();

		foreach ($scores as $s) {
			$scoreFinal[$s->record_challengeuid][$s->record_playerlogin] = $s;
			$posFinal[$s->record_challengeuid][$s->rank] = $s;
			if (!isset($rankLogins[$s->record_playerlogin])) {
				$rankLogins[$s->record_playerlogin] = 0;
				$timeLogins[$s->record_playerlogin] = 0;
			}
			$rankLogins[$s->record_playerlogin] += $s->rank;
			$playerNicknames[$s->record_playerlogin] = ($colorNicks ? $s->player_nickname : Formatting::stripStyles($s->player_nickname));
		}

		foreach ($this->storage->maps as $map) {
			if (isset($scoreFinal[$map->uId])) {
				$lastPlace = count($scoreFinal[$map->uId]) + 1;
			} else {
				$lastPlace = 1;
			}
			foreach($rankLogins as $login => $sum) {
				if (!isset($scoreFinal[$map->uId][$login])) {
					$rankLogins[$login] += $lastPlace;
					if (isset($posFinal[$map->uId]) && $posFinal[$map->uId]) {
						$timeLogins[$login] += ($posFinal[$map->uId][count($posFinal[$map->uId])]->record_score);
					}
					$scoreFinal[$map->uId][$login] = (object) array('record_challengeuid' => $map->uId, 'record_playerlogin' => $login, 'record_score' => 0, 'player_nickname' => $playerNicknames[$login], 'rank' => $lastPlace);
				} else {
					$timeLogins[$login] += $scoreFinal[$map->uId][$login]->record_score;
				}
			}
		}

		foreach($rankLogins as $login => $sum) {
			$rankLogins[$login] = $sum / count($this->storage->maps);
		}

		return (array($scoreFinal, $rankLogins, $playerNicknames, $posFinal, $timeLogins));
	}

	private function buildHtmlTable($scores, $showTime, $replayURL = null) {
		($showTime ? asort($scores[4]) : asort($scores[1]));

		$out = "<h3>Current Rankings</h3>" . PHP_EOL;
		$out .= "<table>" . PHP_EOL;

		$out .= "<tr>" . PHP_EOL;
		$out .= "<th>Login</th>" . PHP_EOL;
		$out .= "<th>Nickname</th>" . PHP_EOL;
		foreach ($this->storage->maps as $map) {
			$out .= "<th>" . Formatting::toHtml(str_ireplace(array('$<', '$>'), '', $map->name)) . "</th>" . PHP_EOL;
		}
		$out .= ($showTime ? "<th>Overall Time</th>" : "<th>Average</th>") . PHP_EOL;
		$out .= "<th>Rank</th>" . PHP_EOL;
		$out .= "</tr>" . PHP_EOL;

		$place = 1;
		foreach (($showTime ? $scores[4] : $scores[1]) as $login => $avg) {
			$out .= "<tr>" . PHP_EOL;

			$out .= "<td>" . $login . "</td>" . PHP_EOL;
			$out .= "<td>" . Formatting::toHtml(str_ireplace(array('$<', '$>'), '', $scores[2][$login])) . "</td>" . PHP_EOL;
			foreach ($this->storage->maps as $map) {
				if ($scores[0][$map->uId][$login]->record_score <= 0) {
					$out .= '<td><span style="color:#D55">' . $scores[0][$map->uId][$login]->rank . "</span></td>" . PHP_EOL;
				} else {
					if ($replayURL) {
						$out .= "<td><a href=\"" . $replayURL . $scores[0][$map->uId][$login]->record_challengeuid . "/" . $scores[0][$map->uId][$login]->record_playerlogin . "\">" . $scores[0][$map->uId][$login]->rank . "</a></td>" . PHP_EOL;
					} else {
						$out .= '<td>' . $scores[0][$map->uId][$login]->rank . "</td>" . PHP_EOL;
					}
				}
			}
			$out .= "<td>" . ($showTime ? $this->formatTime($avg) : round($avg, 3)) . "</td>" . PHP_EOL;
			$out .= "<td>" . $place . "</td>" . PHP_EOL;

			$out .= "</tr>" . PHP_EOL;

			++$place;
		}

		$out .= "</table>" . PHP_EOL;
		return $out;
	}
	
	private function buildWrTable($scores, $nb = 3) {
		$out = "<h3>Current World Records</h3>" . PHP_EOL;
		$out .= "<table>" . PHP_EOL;

		$out .= "<tr>" . PHP_EOL;
		$out .= "<th></th>" . PHP_EOL;
		foreach ($this->storage->maps as $map) {
			$out .= "<th>" . Formatting::toHtml(str_ireplace(array('$<', '$>'), '', $map->name)) . "</th>" . PHP_EOL;
		}
		$out .= "</tr>" . PHP_EOL;

		for ($pos = 1; $pos <= $nb; ++$pos) {
			$out .= "<tr>" . PHP_EOL;
			$out .= "<td>" . $pos . "</td>" . PHP_EOL;
			foreach ($this->storage->maps as $map) {
				if (isset($scores[3][$map->uId][$pos])) {
					$out .= "<td>" . Formatting::toHtml(str_ireplace(array('$<', '$>'), '', $scores[3][$map->uId][$pos]->player_nickname))
					. "<br><span style=\"font-size: 12pt\">" . $this->formatTime($scores[3][$map->uId][$pos]->record_score) . "</span></td>" . PHP_EOL;
				} else {
					$out .= "<td></td>" . PHP_EOL;
				}
			}
			$out .= "</tr>" . PHP_EOL;
		}

		$out .= "</table>" . PHP_EOL;
		return $out;
	}

	private function prettyPrintForDiscord($scores)
	{
		$out = array();
		$maxLenAvg = 0;
		$maxLenLogin = 0;
		foreach ($scores[1] as $login => $avg) {
			if (strlen($avg) > $maxLenAvg) {
				$maxLenAvg = strlen($avg);
			}
			if (strlen($login) > $maxLenLogin) {
				$maxLenLogin = strlen($login);
			}
		}
		$maxLenAvg += 5;
		$maxLenLogin += 10;

		$buffer = "```\n" . str_pad("Rank", 5) . str_pad("Average", $maxLenAvg) . str_pad("Login", $maxLenLogin) . "Nickname\n\n";

		$place = 1;
		foreach ($scores[1] as $login => $avg) {
			$tbuffer = str_pad($place, 5) . str_pad(round($avg, 2), $maxLenAvg) . str_pad($login, $maxLenLogin) . Formatting::stripStyles($scores[2][$login]) . "\n";
			++$place;

			if (strlen($buffer) + strlen($tbuffer) > 1995) {
				$out[] = $buffer . "```";
				$buffer = "```\n";
			}
			$buffer .= $tbuffer;
		}
		$out[] = $buffer . "```";
		if ($this->config->websiteURL) {
			$out[] = "More infos on " . $this->config->websiteURL;
		}
		return $out;
	}

    public function eXpOnUnload()
    {
        Dispatcher::unregister(LocalEvent::getClass(), $this);
		$this->sendResults();
    }
}
