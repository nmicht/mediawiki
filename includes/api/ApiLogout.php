<?php
/**
 * API for MediaWiki 1.8+
 *
 * Created on Jan 4, 2008
 *
 * Copyright © 2008 Yuri Astrakhan <Firstname><Lastname>@gmail.com,
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	// Eclipse helper - will be ignored in production
	require_once( 'ApiBase.php' );
}

/**
 * API module to allow users to log out of the wiki. API equivalent of
 * Special:Userlogout.
 *
 * @ingroup API
 */
class ApiLogout extends ApiBase {

	public function __construct( $main, $action ) {
		parent::__construct( $main, $action );
	}

	public function execute() {
		global $wgUser;
		$oldName = $wgUser->getName();
		$wgUser->logout();

		// Give extensions to do something after user logout
		$injected_html = '';
		wfRunHooks( 'UserLogoutComplete', array( &$wgUser, &$injected_html, $oldName ) );
	}

	public function isReadMode() {
		return false;
	}

	public function getAllowedParams() {
		return array();
	}

	public function getParamDescription() {
		return array();
	}

	public function getDescription() {
		return 'This module is used to logout and clear session data';
	}

	protected function getExamples() {
		return array(
			'api.php?action=logout'
		);
	}

	public function getVersion() {
		return __CLASS__ . ': $Id: ApiLogout.php 70647 2010-08-07 19:59:42Z ialex $';
	}
}
