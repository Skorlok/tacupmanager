<?php

namespace ManiaLivePlugins\skorlok\tacupmanager;

use ManiaLib\Utils\Singleton;

class Config extends Singleton
{
	public $outputFileName = "./taCupManager/index.html";
	public $templateFileName = "./vendor/skorlok/tacupmanager/template.html";
	public $buildWrTable = -1;
	public $buildPositionTable = true;
	public $displayOverallTimeInsteadOfPosition = false;
	public $replaysURL = "";
	public $discordToken = "";
	public $discordChannelId = "";
	public $websiteURL = "";
}
