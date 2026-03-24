<?php

namespace ManiaLivePlugins\skorlok\tacupmanager;

use ManiaLivePlugins\eXpansion\Core\types\config\types\TypeString;
use ManiaLivePlugins\eXpansion\Core\types\config\types\TypeInt;
use ManiaLivePlugins\eXpansion\Core\types\config\types\Boolean;

class MetaData extends \ManiaLivePlugins\eXpansion\Core\types\config\MetaData
{

    public function onBeginLoad()
    {
        parent::onBeginLoad();
        $this->setName("Other: TA Cup Manager");
        $this->setDescription("Export LocalRecords into .html file.");
        $this->setGroups(array("Other"));

        $this->setRelaySupport(false);

        $config = Config::getInstance();

        $var = new TypeString("outputFileName", "Output HTML file", $config, false, false);
		$var->setDescription("the path (relative or absolute) to the output file");
		$var->setDefaultValue("./taCupManager/index.html");
        $this->registerVariable($var);

        $var = new TypeString("templateFileName", "Template HTML file", $config, false, false);
        $var->setDescription("the path (relative or absolute) to the template file, used to generate the output file, it must contains the placeholder %content% where the table will be inserted");
        $var->setDefaultValue("./taCupManager/template.html");
        $this->registerVariable($var);

        $var = new TypeInt("buildWrTable", "Build the panel with the X first players and their time ?", $config, false, false);
		$var->setDescription("use -1 to disable");
        $var->setDefaultValue(-1);
        $this->registerVariable($var);
		
		$var = new Boolean("buildPositionTable", "Build the main table with positions ?", $config, false, false);
        $var->setDefaultValue(true);
        $this->registerVariable($var);

        $var = new Boolean("displayOverallTimeInsteadOfPosition", "Display overall time instead of position in the main table ?", $config, false, false);
        $var->setDefaultValue(false);
        $this->registerVariable($var);
		
		$var = new TypeString("replaysURL", "URL of the replay files, if any", $config, false, false);
		$var->setDescription("exemple: https://replays.skorlok.com/, files must be named ServerLogin/UID/Login");
        $this->registerVariable($var);
		
		$var = new TypeString("discordToken", "Token of the Discord bot, if you want", $config, false, false);
		$var->setDescription("Used to publish results on Discord");
        $this->registerVariable($var);

        $var = new TypeString("discordChannelId", "Discord channel ID, IT PURGE THE CHANNEL", $config, false, false);
        $var->setDescription("Used to publish results on Discord");
        $this->registerVariable($var);

        $var = new TypeString("websiteURL", "URL of the website where the results will be published", $config, false, false);
        $var->setDescription("Post this link at the end of the Discord message");
        $this->registerVariable($var);
    }
}
