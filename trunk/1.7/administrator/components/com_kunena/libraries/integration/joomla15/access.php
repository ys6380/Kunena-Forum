<?php
/**
 * @version $Id$
 * Kunena Component
 * @package Kunena
 *
 * @Copyright (C) 2008 - 2010 Kunena Team All rights reserved
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.org
 *
 **/
//
// Dont allow direct linking
defined( '_JEXEC' ) or die('');

class KunenaAccessJoomla15 extends KunenaAccess {
	function __construct() {
		if (is_dir(JPATH_LIBRARIES.'/joomla/access'))
			return null;
		$this->priority = 25;
	}

	protected function loadAdmins() {
		$this->adminsByCatid = array ();
		$this->adminsByUserid = array ();
		$db = JFactory::getDBO ();
		$query = "SELECT u.id FROM #__users AS u
			WHERE u.block='0' AND u.usertype IN ('Administrator', 'Super Administrator')";
		$db->setQuery ( $query );
		$list = $db->loadResultArray ();
		KunenaError::checkDatabaseError ();
		foreach ( $list as $item ) {
			$userid = intval ( $item );
			$this->adminsByCatid [0] [$userid] = 1;
			$this->adminsByUserid [$userid] [0] = 1;
		}
	}

	protected function loadModerators() {
		$this->moderatorsByCatid = array ();
		$this->moderatorsByUserid = array ();
		$db = JFactory::getDBO ();
		$query = "SELECT u.id AS userid, m.catid
				FROM #__users AS u
				INNER JOIN #__kunena_users AS ku ON u.id=ku.userid
				LEFT JOIN #__kunena_moderation AS m ON u.id=m.userid
				LEFT JOIN #__kunena_categories AS c ON m.catid=c.id
				WHERE u.block='0' AND ku.moderator='1' AND (m.catid IS NULL OR c.moderated='1')";
		$db->setQuery ( $query );
		$list = $db->loadObjectList ();
		if (KunenaError::checkDatabaseError ())
			return;
		foreach ( $list as $item ) {
			$userid = intval ( $item->userid );
			$catid = intval ( $item->catid );
			$this->moderatorsByUserid [$userid] [$catid] = 1;
			$this->moderatorsByCatid [$catid] [$userid] = 1;
		}
	}

	protected function loadAllowedCategories($userid) {
		$acl = JFactory::getACL ();
		$db = JFactory::getDBO ();
		$user = JFactory::getUser();

		if ($userid != 0) {
			$aro_group = $acl->getAroGroup ( $userid );
			$gid = $aro_group->id;
		} else {
			$gid = 0;
		}

		$query = "SELECT id, accesstype, access, pub_access, pub_recurse, admin_access, admin_recurse
				FROM #__kunena_categories
				WHERE published='1' AND (accesstype='none' OR accesstype='joomla')";
		$db->setQuery ( $query );
		$rows = $db->loadObjectList ();
		if (KunenaError::checkDatabaseError()) return array();
		$catlist = array();
		foreach ( $rows as $row ) {
			if (self::isModerator($userid, $row->id)) {
				$catlist[$row->id] = 1;
			} elseif ($row->accesstype == 'joomla') {
				if ( $row->access <= $user->get('aid') )
					$catlist[$row->id] = 1;
			} elseif ($row->pub_access == 0 ||
				($userid > 0 && (
				($row->pub_access == - 1)
				|| ($row->pub_access > 0 && self::_has_rights ( $gid, $row->pub_access, $row->pub_recurse ))
				|| ($row->admin_access > 0 && self::_has_rights ( $gid, $row->admin_access, $row->admin_recurse ))))) {
				$catlist[$row->id] = 1;
			}
		}
		return $catlist;
	}

	protected function _has_rights($usergroup, $groupid, $recurse) {
		if ($usergroup == $groupid)
			return 1;
		if ($usergroup && $recurse) {
			$childs = $this->_get_groups($groupid, $recurse);
			if (in_array ( $usergroup, $childs ));
				return 1;
		}
		return 0;
	}

	protected function _get_groups($groupid, $recurse) {
		static $groups = array();

		if (isset ($groups[$groupid]))
			return $groups[$groupid];

		if ($groupid > 0 && $recurse) {
			$acl = JFactory::getACL ();
			$groups[$groupid] = $acl->get_group_children ( $groupid, 'ARO', 'RECURSE' );
			$groups[$groupid][] = $groupid;
			return $groups[$groupid];
		}
		return array($groupid);
	}

	protected function _get_subscribers($catid, $thread) {
		$db = JFactory::getDBO ();
		$query ="SELECT user_id FROM #__kunena_user_topics WHERE topic_id={$thread}
				UNION
				SELECT user_id FROM #__kunena_user_categories WHERE category_id={$catid}";
		$db->setQuery ($query);
		$userids = $db->loadResultArray();
		KunenaError::checkDatabaseError();
		return $userids;
	}

	function getSubscribers($catid, $thread, $subscriptions = false, $moderators = false, $admins = false, $excludeList = '0') {
		$catid = intval ( $catid );
		$thread = intval ( $thread );
		if (! $catid || ! $thread)
			return array();

		// Make sure that category exists and fetch access info
		$db = JFactory::getDBO ();
		$query = "SELECT pub_access, pub_recurse, admin_access, admin_recurse FROM #__kunena_categories WHERE id={$catid}";
		$db->setQuery ($query);
		$access = $db->loadObject ();
		if (KunenaError::checkDatabaseError() || !$access) return array();

		$subslist = -1;
		$modlist = -1;
		$adminlist = -1;
		$arogroups = '';
		if ($subscriptions) {
			// Get all allowed Joomla groups to make sure that subscription is valid
			$kunena_acl = JFactory::getACL ();
			$public = $this->_get_groups($access->pub_access, $access->pub_recurse);
			$admin = array();
			if ($access->pub_access > 0) {
				$admin = $this->_get_groups($access->admin_access, $access->admin_recurse);
			}
			$arogroups = implode ( ',', array_unique ( array_merge ( $public, $admin ) ) );
			if ($arogroups)
				$arogroups = "u.gid IN ({$arogroups})";
			$subslist = $this->_get_subscribers($catid, $thread);
			$subslist = !empty($subslist) ? implode(',', array_keys($subslist)) : '-1';
		}
		if ($moderators) {
			if ($this->moderatorsByCatid === false) {
				$this->loadModerators();
			}
			if (!empty($this->moderatorsByCatid[0])) $modlist = $this->moderatorsByCatid[0];
			if (!empty($this->moderatorsByCatid[$catid])) $modlist += $this->moderatorsByCatid[$catid];
			$modlist = !empty($modlist) ? implode(',', array_keys($modlist)) : '-1';
		}
		if ($admins) {
			if ($this->adminsByCatid === false) {
				$this->loadAdmins();
			}
			if (!empty($this->adminsByCatid[0])) $adminlist = $this->adminsByCatid[0];
			if (!empty($this->adminsByCatid[$catid])) $adminlist += $this->adminsByCatid[$catid];
			$adminlist = !empty($adminlist) ? implode(',', array_keys($adminlist)) : '-1';
		}

		$querysel = "SELECT u.id, u.name, u.username, u.email,
					IF( u.id IN ({$subslist}), 1, 0 ) AS subscription,
					IF( u.id IN ({$modlist}), 1, 0 ) AS moderator,
					IF( u.id IN ({$adminlist}), 1, 0 ) AS admin
					FROM #__users AS u";

		$where = array ();
		if ($subscriptions)
			$where [] = " ( u.id IN ({$subslist}) ) " . ($arogroups ? " AND {$arogroups}" : '') . " ) ";
		if ($moderators)
			$where [] = " ( u.id IN ({$modlist}) ) ";
		if ($admins)
			$where [] = " ( u.id IN ({$adminlist}) ) ";

		$subsList = array ();
		if (count ($where)) {
			$where = " AND (" . implode ( ' OR ', $where ) . ")";
			$query = $querysel . " WHERE u.block=0 AND u.id NOT IN ($excludeList) $where";
			$db->setQuery ( $query );
			$subsList = $db->loadObjectList ();
			if (KunenaError::checkDatabaseError()) return array();
		}

		unset($subslist, $modlist, $adminlist);
		return $subsList;
	}
}
