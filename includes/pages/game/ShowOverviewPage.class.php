<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.x Koray Karakuş <koraykarakus@yahoo.com>
 * @link https://github.com/jkroepke/2Moons
 */

class ShowOverviewPage extends AbstractGamePage
{
	public static $requireModule = 0;

	function __construct()
	{
		parent::__construct();
	}

	private function GetTeamspeakData()
	{
		global $USER, $LNG;

		$config = Config::get();

		if ($config->ts_modon == 0)
		{
			return false;
		}

		Cache::get()->add('teamspeak', 'TeamspeakBuildCache');
		$tsInfo	= Cache::get()->getData('teamspeak', false);

		if(empty($tsInfo))
		{
			return array(
				'error'	=> $LNG['ov_teamspeak_not_online']
			);
		}

		$url = '';

		switch($config->ts_version)
		{
			case 2:
				$url = 'teamspeak://%s:%s?nickname=%s';
			break;
			case 3:
				$url = 'ts3server://%s?port=%d&amp;nickname=%s&amp;password=%s';
			break;
		}

		return array(
			'url'		=> sprintf($url, $config->ts_server, $config->ts_tcpport, $USER['username'], $tsInfo['password']),
			'current'	=> $tsInfo['current'],
			'max'		=> $tsInfo['maxuser'],
			'error'		=> false,
		);
	}



	// unused?
	function savePlanetAction()
	{
		global $USER, $PLANET, $LNG;
		$password =	HTTP::_GP('password', '', true);
		if (!empty($password))
		{
			$db = Database::get();
            $sql = "SELECT COUNT(*) as state FROM %%FLEETS%% WHERE
                      (fleet_owner = :userID AND (fleet_start_id = :planetID OR fleet_start_id = :lunaID)) OR
                      (fleet_target_owner = :userID AND (fleet_end_id = :planetID OR fleet_end_id = :lunaID));";
            $IfFleets = $db->selectSingle($sql, array(
                ':userID'   => $USER['id'],
                ':planetID' => $PLANET['id'],
                ':lunaID'   => $PLANET['id_luna']
            ), 'state');

            if ($IfFleets > 0)
				exit(json_encode(array('message' => $LNG['ov_abandon_planet_not_possible'])));
			elseif ($USER['id_planet'] == $PLANET['id'])
				exit(json_encode(array('message' => $LNG['ov_principal_planet_cant_abanone'])));
			elseif (PlayerUtil::cryptPassword($password) != $USER['password'])
				exit(json_encode(array('message' => $LNG['ov_wrong_pass'])));
			else
			{
				if($PLANET['planet_type'] == 1) {
					$sql = "UPDATE %%PLANETS%% SET destruyed = :time WHERE id = :planetID;";
                    $db->update($sql, array(
                        ':time'   => TIMESTAMP + 86400,
                        ':planetID' => $PLANET['id'],
                    ));
                    $sql = "DELETE FROM %%PLANETS%% WHERE id = :lunaID;";
                    $db->delete($sql, array(
                        ':lunaID' => $PLANET['id_luna']
                    ));
                } else {
                    $sql = "UPDATE %%PLANETS%% SET id_luna = 0 WHERE id_luna = :planetID;";
                    $db->update($sql, array(
                        ':planetID' => $PLANET['id'],
                    ));
                    $sql = "DELETE FROM %%PLANETS%% WHERE id = :planetID;";
                    $db->delete($sql, array(
                        ':planetID' => $PLANET['id'],
                    ));
                }

				$PLANET['id']	= $USER['id_planet'];
				exit(json_encode(array('ok' => true, 'message' => $LNG['ov_planet_abandoned'])));
			}
		}
	}

	function show()
	{
		global $LNG, $PLANET, $USER, $config;

		$AdminsOnline = $chatOnline  = $Moon = $RefLinks = array();

    $db = Database::get();

		if ($PLANET['id_luna'] != 0) {

				$sql = "SELECT id, name, planet_type, image FROM %%PLANETS%% WHERE id = :moonID;";

				$Moon = $db->selectSingle($sql, array(
            ':moonID'   => $PLANET['id_luna']
        ));
    }elseif ($PLANET['planet_type'] == 3) {
			$sql = "SELECT id, name, planet_type, image FROM %%PLANETS%% WHERE id_luna = :moonID;";

			$Moon = $db->selectSingle($sql, array(
					':moonID'   => $PLANET['id']
			));
    }


		if ($PLANET['b_building'] - TIMESTAMP > 0) {

			$Queue			= unserialize($PLANET['b_building_id']);
			$buildInfo['buildings']	= array(
				'id'		=> $Queue[0][0],
				'level'		=> $Queue[0][1],
				'timeleft'	=> $PLANET['b_building'] - TIMESTAMP,
				'time'		=> $PLANET['b_building'],
				'starttime'	=> pretty_time($PLANET['b_building'] - TIMESTAMP),
			);

		}else {

			$buildInfo['buildings']	= false;

		}

		if (!empty($PLANET['b_hangar_id'])) {

			$Queue	= unserialize($PLANET['b_hangar_id']);

			$time	= BuildFunctions::getBuildingTime($USER, $PLANET, $Queue[0][0]) * $Queue[0][1];

			$buildInfo['fleet']	= array(
				'id'		=> $Queue[0][0],
				'level'		=> $Queue[0][1],
				'timeleft'	=> $time - $PLANET['b_hangar'],
				'time'		=> $time,
				'starttime'	=> pretty_time($time - $PLANET['b_hangar']),
			);

		}else {

			$buildInfo['fleet']	= false;

		}

		if ($USER['b_tech'] - TIMESTAMP > 0) {

			$Queue			= unserialize($USER['b_tech_queue']);

			$buildInfo['tech']	= array(
				'id'		=> $Queue[0][0],
				'level'		=> $Queue[0][1],
				'timeleft'	=> $USER['b_tech'] - TIMESTAMP,
				'time'		=> $USER['b_tech'],
				'starttime'	=> pretty_time($USER['b_tech'] - TIMESTAMP),
			);

		}else {

			$buildInfo['tech']	= false;

		}


		$sql = "SELECT id,username FROM %%USERS%% WHERE universe = :universe AND onlinetime >= :onlinetime AND authlevel > :authlevel;";

		$onlineAdmins = $db->select($sql, array(
        ':universe'     => Universe::current(),
        ':onlinetime'   => TIMESTAMP - 10 * 60,
        ':authlevel'    => AUTH_USR
    ));

    foreach ($onlineAdmins as $AdminRow) {
			$AdminsOnline[$AdminRow['id']]	= $AdminRow['username'];
		}

    $sql = "SELECT userName FROM %%CHAT_ON%% WHERE dateTime > DATE_SUB(NOW(), interval 2 MINUTE) AND channel = 0";

		$chatUsers = $db->select($sql);

    foreach ($chatUsers as $chatRow) {
			$chatOnline[]	= $chatRow['userName'];
		}

		// Fehler: Wenn Spieler gelöscht werden, werden sie nicht mehr in der Tabelle angezeigt.
		$sql = "SELECT u.id, u.username, s.total_points FROM %%USERS%% as u
		LEFT JOIN %%USER_POINTS%% as s ON s.id_owner = u.id WHERE ref_id = :userID;";

		$RefLinksRAW = $db->select($sql, array(
        ':userID'   => $USER['id']
    ));

    if($config->ref_active){

			foreach ($RefLinksRAW as $RefRow) {
				$RefLinks[$RefRow['id']]	= array(
					'username'	=> $RefRow['username'],
					'points'	=> min($RefRow['total_points'], $config->ref_minpoints)
				);
			}

		}

		$sql	= 'SELECT total_points, total_rank
		FROM %%USER_POINTS%%
		WHERE id_owner = :userId;';

		$statData	= $db->selectSingle($sql, array(
			':userId'	=> $USER['id'],
		));

		if (!$statData) {
			$rankInfo	= "-";
		}else {
			$rankInfo	= sprintf(
			$LNG['ov_userrank_info'],
			pretty_number($statData['total_points']),
			$LNG['ov_place'],
			$statData['total_rank'],
			$statData['total_rank'],
			$LNG['ov_of'],
			$config->users_amount
			);
		}



		$sql = "SELECT COUNT(*) as count FROM %%USERS%% WHERE onlinetime >= UNIX_TIMESTAMP(NOW() - INTERVAL 15 MINUTE);";
		$usersOnline = $db->selectSingle($sql,array(),'count');

		$sql = "SELECT COUNT(*) as count FROM %%FLEETS%%;";
		$fleetsOnline = $db->selectSingle($sql,array(),'count');

		$this->assign(array(
			'rankInfo'					=> $rankInfo,
			'is_news'					=> $config->OverviewNewsFrame,
			'news'						=> makebr($config->OverviewNewsText),
			'usersOnline'				=> $usersOnline,
			'fleetsOnline'				=> $fleetsOnline,
			'planetname'				=> $PLANET['name'],
			'planetimage'				=> $PLANET['image'],
			'galaxy'					=> $PLANET['galaxy'],
			'system'					=> $PLANET['system'],
			'planet'					=> $PLANET['planet'],
			'planet_type'				=> $PLANET['planet_type'],
			'username'					=> $USER['username'],
			'userid'					=> $USER['id'],
			'buildInfo'					=> $buildInfo,
			'Moon'						=> $Moon,
			'AdminsOnline'				=> $AdminsOnline,
			'teamspeakData'				=> $this->GetTeamspeakData(),
			'planet_diameter'			=> pretty_number($PLANET['diameter']),
			'planet_field_current' 		=> $PLANET['field_current'],
			'planet_field_max' 			=> CalculateMaxPlanetFields($PLANET),
			'planet_temp_min' 			=> $PLANET['temp_min'],
			'planet_temp_max' 			=> $PLANET['temp_max'],
			'planet_id' => $PLANET['id'],
			'ref_active'				=> $config->ref_active,
			'ref_minpoints'				=> $config->ref_minpoints,
			'RefLinks'					=> $RefLinks,
			'chatOnline'				=> $chatOnline,
			'path'						=> HTTP_PATH,
		));

		$this->display('page.overview.default.tpl');
	}

	function actions()
	{
		global $LNG, $PLANET;

		$this->initTemplate();

		$this->setWindow('popup');

		$this->assign(array(
			'ov_security_confirm'		=> sprintf($LNG['ov_security_confirm'], $PLANET['name'].' ['.$PLANET['galaxy'].':'.$PLANET['system'].':'.$PLANET['planet'].']'),
		));

		$this->display('page.overview.actions.tpl');
	}

	function rename()
	{
		global $LNG, $PLANET;

		$newname  = HTTP::_GP('name', '', UTF8_SUPPORT);

		$error = array();

		if (empty($newname)) {
			$error[] = $LNG['ov_ac_error_1'];
		}

		if (strlen($newname) > 20) {
			$error[] = $LNG['ov_ac_error_2'];
		}

		if (!PlayerUtil::isNameValid($newname)) {
			$error[] = $LNG['ov_newname_specialchar'];
		}

		if (!empty($error)) {
			$this->sendJSON($error);
		}

		$db = Database::get();

		$sql = "UPDATE %%PLANETS%% SET name = :newName WHERE id = :planetID;";

		$db->update($sql, array(
				':newName'  => $newname,
				':planetID' => $PLANET['id']
		));

		$this->sendJSON($LNG['ov_newname_done']);

	}

	function delete()
	{
		global $LNG, $PLANET, $USER;

		$error = array();

		$planetName	= HTTP::_GP('planetName', '', true);

		if (empty($planetName)) {
			$error[] = $LNG['ov_ac_error_3'];
		}

		$db = Database::get();

		$sql = "SELECT COUNT(*) as count FROM %%FLEETS%% WHERE
						(fleet_owner = :userID AND (fleet_start_id = :planetID OR fleet_start_id = :lunaID)) OR
						(fleet_target_owner = :userID AND (fleet_end_id = :planetID OR fleet_end_id = :lunaID));";

		$IfFleets = $db->selectSingle($sql, array(
				':userID'   => $USER['id'],
				':planetID' => $PLANET['id'],
				':lunaID'   => $PLANET['id_luna']
		), 'count');

		if ($IfFleets > 0) {
			$error[] = $LNG['ov_abandon_planet_not_possible'];
		}

		if ($USER['id_planet'] == $PLANET['id']) {
			$error[] =  $LNG['ov_principal_planet_cant_abanone'];
		}

		if ($planetName != $PLANET['name']) {
			$error[] = $LNG['ov_wrong_name'];
		}

		if (!empty($error)) {
			$this->sendJSON($error);
		}


		if ($USER['b_tech_planet'] == $PLANET['id'] && !empty($USER['b_tech_queue'])) {
			$TechQueue = unserialize($USER['b_tech_queue']);
			$NewCurrentQueue = array();
			foreach($TechQueue as $ID => $ListIDArray) {
				if ($ListIDArray[4] == $PLANET['id']) {
					$ListIDArray[4] = $USER['id_planet'];
					$NewCurrentQueue[] = $ListIDArray;
				}
			}

			$USER['b_tech_planet'] = $USER['id_planet'];
			$USER['b_tech_queue'] = serialize($NewCurrentQueue);
		}

		if($PLANET['planet_type'] == 1) {
				$sql = "UPDATE %%PLANETS%% SET destruyed = :time WHERE id = :planetID;";
				$db->update($sql, array(
						':time'   => TIMESTAMP+ 86400,
						':planetID' => $PLANET['id'],
				));
				$sql = "DELETE FROM %%PLANETS%% WHERE id = :lunaID;";
				$db->delete($sql, array(
						':lunaID' => $PLANET['id_luna']
				));
		} else {
				$sql = "UPDATE %%PLANETS%% SET id_luna = 0 WHERE id_luna = :planetID;";
				$db->update($sql, array(
						':planetID' => $PLANET['id'],
				));
				$sql = "DELETE FROM %%PLANETS%% WHERE id = :planetID;";
				$db->delete($sql, array(
						':planetID' => $PLANET['id'],
				));
		}

		Session::load()->planetId = $USER['id_planet'];
		$this->sendJSON($LNG['ov_planet_abandoned']);


	}
}
