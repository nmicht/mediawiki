<?php
/**
 * MySQL-specific installer.
 *
 * @file
 * @ingroup Deployment
 */

/**
 * Class for setting up the MediaWiki database using MySQL.
 *
 * @ingroup Deployment
 * @since 1.17
 */
class MysqlInstaller extends DatabaseInstaller {

	protected $globalNames = array(
		'wgDBserver',
		'wgDBname',
		'wgDBuser',
		'wgDBpassword',
		'wgDBprefix',
		'wgDBTableOptions',
		'wgDBmysql5',
	);

	protected $internalDefaults = array(
		'_MysqlEngine' => 'InnoDB',
		'_MysqlCharset' => 'binary',
		'_InstallUser' => 'root',
	);

	public $supportedEngines = array( 'InnoDB', 'MyISAM' );

	public $minimumVersion = '4.0.14';

	public $webUserPrivs = array(
		'DELETE',
		'INSERT',
		'SELECT',
		'UPDATE',
		'CREATE TEMPORARY TABLES',
	);

	public function getName() {
		return 'mysql';
	}

	public function __construct( $parent ) {
		parent::__construct( $parent );
	}

	public function isCompiled() {
		return self::checkExtension( 'mysql' );
	}

	public function getGlobalDefaults() {
		return array();
	}

	public function getConnectForm() {
		return
			$this->getTextBox( 'wgDBserver', 'config-db-host', array(), $this->parent->getHelpBox( 'config-db-host-help' ) ) .
			Html::openElement( 'fieldset' ) .
			Html::element( 'legend', array(), wfMsg( 'config-db-wiki-settings' ) ) .
			$this->getTextBox( 'wgDBname', 'config-db-name', array(), $this->parent->getHelpBox( 'config-db-name-help' ) ) .
			$this->getTextBox( 'wgDBprefix', 'config-db-prefix', array(), $this->parent->getHelpBox( 'config-db-prefix-help' ) ) .
			Html::closeElement( 'fieldset' ) .
			$this->getInstallUserBox();
	}

	public function submitConnectForm() {
		// Get variables from the request.
		$newValues = $this->setVarsFromRequest( array( 'wgDBserver', 'wgDBname', 'wgDBprefix' ) );

		// Validate them.
		$status = Status::newGood();
		if ( !strlen( $newValues['wgDBserver'] ) ) {
			$status->fatal( 'config-missing-db-host' );
		}
		if ( !strlen( $newValues['wgDBname'] ) ) {
			$status->fatal( 'config-missing-db-name' );
		} elseif ( !preg_match( '/^[a-z0-9_-]+$/i', $newValues['wgDBname'] ) ) {
			$status->fatal( 'config-invalid-db-name', $newValues['wgDBname'] );
		}
		if ( !preg_match( '/^[a-z0-9_-]*$/i', $newValues['wgDBprefix'] ) ) {
			$status->fatal( 'config-invalid-db-prefix', $newValues['wgDBprefix'] );
		}
		if ( !$status->isOK() ) {
			return $status;
		}

		// Submit user box
		$status = $this->submitInstallUserBox();
		if ( !$status->isOK() ) {
			return $status;
		}

		// Try to connect
		$status = $this->getConnection();
		if ( !$status->isOK() ) {
			return $status;
		}
		$conn = $status->value;

		// Check version
		$version = $conn->getServerVersion();
		if ( version_compare( $version, $this->minimumVersion ) < 0 ) {
			return Status::newFatal( 'config-mysql-old', $this->minimumVersion, $version );
		}

		return $status;
	}

	public function openConnection() {
		$status = Status::newGood();
		try {
			$db = new DatabaseMysql(
				$this->getVar( 'wgDBserver' ),
				$this->getVar( '_InstallUser' ),
				$this->getVar( '_InstallPassword' ),
				false,
				false,
				0,
				$this->getVar( 'wgDBprefix' )
			);
			$status->value = $db;
		} catch ( DBConnectionError $e ) {
			$status->fatal( 'config-connection-error', $e->getMessage() );
		}
		return $status;
	}

	public function preUpgrade() {
		global $wgDBuser, $wgDBpassword;

		$status = $this->getConnection();
		if ( !$status->isOK() ) {
			$this->parent->showStatusError( $status );
			return;
		}
		$conn = $status->value;
		$conn->selectDB( $this->getVar( 'wgDBname' ) );

		# Determine existing default character set
		if ( $conn->tableExists( "revision" ) ) {
			$revision = $conn->buildLike( $this->getVar( 'wgDBprefix' ) . 'revision' );
			$res = $conn->query( "SHOW TABLE STATUS $revision", __METHOD__ );
			$row = $conn->fetchObject( $res );
			if ( !$row ) {
				$this->parent->showMessage( 'config-show-table-status' );
				$existingSchema = false;
				$existingEngine = false;
			} else {
				if ( preg_match( '/^latin1/', $row->Collation ) ) {
					$existingSchema = 'mysql4';
				} elseif ( preg_match( '/^utf8/', $row->Collation ) ) {
					$existingSchema = 'utf8';
				} elseif ( preg_match( '/^binary/', $row->Collation ) ) {
					$existingSchema = 'binary';
				} else {
					$existingSchema = false;
					$this->parent->showMessage( 'config-unknown-collation' );
				}
				if ( isset( $row->Engine ) ) {
					$existingEngine = $row->Engine;
				} else {
					$existingEngine = $row->Type;
				}
			}
		} else {
			$existingSchema = false;
			$existingEngine = false;
		}

		if ( $existingSchema && $existingSchema != $this->getVar( '_MysqlCharset' ) ) {
			$this->setVar( '_MysqlCharset', $existingSchema );
		}
		if ( $existingEngine && $existingEngine != $this->getVar( '_MysqlEngine' ) ) {
			$this->setVar( '_MysqlEngine', $existingEngine );
		}

		# Normal user and password are selected after this step, so for now
		# just copy these two
		$wgDBuser = $this->getVar( '_InstallUser' );
		$wgDBpassword = $this->getVar( '_InstallPassword' );
	}

	/**
	 * Get a list of storage engines that are available and supported
	 */
	public function getEngines() {
		$engines = array( 'InnoDB', 'MyISAM' );
		$status = $this->getConnection();
		if ( !$status->isOK() ) {
			return $engines;
		}
		$conn = $status->value;

		$version = $conn->getServerVersion();
		if ( version_compare( $version, "4.1.2", "<" ) ) {
			// No SHOW ENGINES in this version
			return $engines;
		}

		$engines = array();
		$res = $conn->query( 'SHOW ENGINES', __METHOD__ );
		foreach ( $res as $row ) {
			if ( $row->Support == 'YES' || $row->Support == 'DEFAULT' ) {
				$engines[] = $row->Engine;
			}
		}
		$engines = array_intersect( $this->supportedEngines, $engines );
		return $engines;
	}

	/**
	 * Get a list of character sets that are available and supported
	 */
	public function getCharsets() {
		$status = $this->getConnection();
		$mysql5 = array( 'binary', 'utf8' );
		$mysql4 = array( 'mysql4' );
		if ( !$status->isOK() ) {
			return $mysql5;
		}
		if ( version_compare( $status->value->getServerVersion(), '4.1.0', '>=' ) ) {
			return $mysql5;
		}
		return $mysql4;
	}

	/**
	 * Return true if the install user can create accounts
	 */
	public function canCreateAccounts() {
		$status = $this->getConnection();
		if ( !$status->isOK() ) {
			return false;
		}
		$conn = $status->value;

		// Check version, need INFORMATION_SCHEMA and CREATE USER
		if ( version_compare( $conn->getServerVersion(), '5.0.2', '<' ) ) {
			return false;
		}

		// Get current account name
		$currentName = $conn->selectField( '', 'CURRENT_USER()', '', __METHOD__ );
		$parts = explode( '@', $currentName );
		if ( count( $parts ) != 2 ) {
			return false;
		}
		$quotedUser = $conn->addQuotes( $parts[0] ) .
			'@' . $conn->addQuotes( $parts[1] );

		// The user needs to have INSERT on mysql.* to be able to CREATE USER
		// The grantee will be double-quoted in this query, as required
		$res = $conn->select( 'INFORMATION_SCHEMA.USER_PRIVILEGES', '*',
			array( 'GRANTEE' => $quotedUser ), __METHOD__ );
		$insertMysql = false;
		$grantOptions = array_flip( $this->webUserPrivs );
		foreach ( $res as $row ) {
			if ( $row->PRIVILEGE_TYPE == 'INSERT' ) {
				$insertMysql = true;
			}
			if ( $row->IS_GRANTABLE ) {
				unset( $grantOptions[$row->PRIVILEGE_TYPE] );
			}
		}

		// Check for DB-specific privs for mysql.*
		if ( !$insertMysql ) {
			$row = $conn->selectRow( 'INFORMATION_SCHEMA.SCHEMA_PRIVILEGES', '*',
				array(
					'GRANTEE' => $quotedUser,
					'TABLE_SCHEMA' => 'mysql',
					'PRIVILEGE_TYPE' => 'INSERT',
				), __METHOD__ );
			if ( $row ) {
				$insertMysql = true;
			}
		}

		if ( !$insertMysql ) {
			return false;
		}

		// Check for DB-level grant options
		$res = $conn->select( 'INFORMATION_SCHEMA.SCHEMA_PRIVILEGES', '*',
			array(
				'GRANTEE' => $quotedUser,
				'IS_GRANTABLE' => 1,
			), __METHOD__ );
		foreach ( $res as $row ) {
			$regex = $conn->likeToRegex( $row->TABLE_SCHEMA );
			if ( preg_match( $regex, $this->getVar( 'wgDBname' ) ) ) {
				unset( $grantOptions[$row->PRIVILEGE_TYPE] );
			}
		}
		if ( count( $grantOptions ) ) {
			// Can't grant everything
			return false;
		}
		return true;
	}

	public function getSettingsForm() {
		if ( $this->canCreateAccounts() ) {
			$noCreateMsg = false;
		} else {
			$noCreateMsg = 'config-db-web-no-create-privs';
		}
		$s = $this->getWebUserBox( $noCreateMsg );

		// Do engine selector
		$engines = $this->getEngines();
		// If the current default engine is not supported, use an engine that is
		if ( !in_array( $this->getVar( '_MysqlEngine' ), $engines ) ) {
			$this->setVar( '_MysqlEngine', reset( $engines ) );
		}
		if ( count( $engines ) >= 2 ) {
			$s .= $this->getRadioSet( array(
				'var' => '_MysqlEngine',
				'label' => 'config-mysql-engine',
				'itemLabelPrefix' => 'config-mysql-',
				'values' => $engines
			));
			$s .= $this->parent->getHelpBox( 'config-mysql-engine-help' );
		}

		// If the current default charset is not supported, use a charset that is
		$charsets = $this->getCharsets();
		if ( !in_array( $this->getVar( '_MysqlCharset' ), $charsets ) ) {
			$this->setVar( '_MysqlCharset', reset( $charsets ) );
		}

		// Do charset selector
		if ( count( $charsets ) >= 2 ) {
			$s .= $this->getRadioSet( array(
				'var' => '_MysqlCharset',
				'label' => 'config-mysql-charset',
				'itemLabelPrefix' => 'config-mysql-',
				'values' => $charsets
			));
			$s .= $this->parent->getHelpBox( 'config-mysql-charset-help' );
		}

		return $s;
	}

	public function submitSettingsForm() {
		$this->setVarsFromRequest( array( '_MysqlEngine', '_MysqlCharset' ) );
		$status = $this->submitWebUserBox();
		if ( !$status->isOK() ) {
			return $status;
		}

		// Validate the create checkbox
		$canCreate = $this->canCreateAccounts();
		if ( !$canCreate ) {
			$this->setVar( '_CreateDBAccount', false );
			$create = false;
		} else {
			$create = $this->getVar( '_CreateDBAccount' );
		}

		if ( !$create ) {
			// Test the web account
			try {
				new DatabaseMysql(
					$this->getVar( 'wgDBserver' ),
					$this->getVar( 'wgDBuser' ),
					$this->getVar( 'wgDBpassword' ),
					false,
					false,
					0,
					$this->getVar( 'wgDBprefix' )
				);
			} catch ( DBConnectionError $e ) {
				return Status::newFatal( 'config-connection-error', $e->getMessage() );
			}
		}

		// Validate engines and charsets
		// This is done pre-submit already so it's just for security
		$engines = $this->getEngines();
		if ( !in_array( $this->getVar( '_MysqlEngine' ), $engines ) ) {
			$this->setVar( '_MysqlEngine', reset( $engines ) );
		}
		$charsets = $this->getCharsets();
		if ( !in_array( $this->getVar( '_MysqlCharset' ), $charsets ) ) {
			$this->setVar( '_MysqlCharset', reset( $charsets ) );
		}
		return Status::newGood();
	}

	public function preInstall() {
		# Add our user callback to installSteps, right before the tables are created.
		$callback = array(
			'name' => 'user',
			'callback' => array( $this, 'setupUser' ),
		);
		$this->parent->addInstallStep( $callback, 'tables' );
	}

	public function setupDatabase() {
		$status = $this->getConnection();
		if ( !$status->isOK() ) {
			return $status;
		}
		$conn = $status->value;
		$dbName = $this->getVar( 'wgDBname' );
		if( !$conn->selectDB( $dbName ) ) {
			$conn->query( "CREATE DATABASE " . $conn->addIdentifierQuotes( $dbName ), __METHOD__ );
			$conn->selectDB( $dbName );
		}
		$this->setupSchemaVars();
		return $status;
	}

	public function setupUser() {
		$dbUser = $this->getVar( 'wgDBuser' );
		if( $dbUser == $this->getVar( '_InstallUser' ) ) {
			return Status::newGood();
		}
		$status = $this->getConnection();
		if ( !$status->isOK() ) {
			return $status;
		}

		$this->setupSchemaVars();
		$dbName = $this->getVar( 'wgDBname' );
		$this->db->selectDB( $dbName );
		$server = $this->getVar( 'wgDBserver' );
		$password = $this->getVar( 'wgDBpassword' );
		$grantableNames = array();

		if ( $this->getVar( '_CreateDBAccount' ) ) {
			// Before we blindly try to create a user that already has access,
			try { // first attempt to connect to the database
				new DatabaseMysql(
					$server,
					$dbUser,
					$password,
					false,
					false,
					0,
					$this->getVar( 'wgDBprefix' )
				);
				$grantableNames[] = $this->buildFullUserName( $dbUser, $server );
				$tryToCreate = false;
			} catch ( DBConnectionError $e ) {
				$tryToCreate = true;
			}
		} else {
			$grantableNames[] = $this->buildFullUserName( $dbUser, $server );
			$tryToCreate = false;
		}

		if( $tryToCreate ) {
			$createHostList = array($server,
				'localhost',
				'localhost.localdomain',
				'%'
			);

			$createHostList = array_unique( $createHostList );
			$escPass = $this->db->addQuotes( $password );

			foreach( $createHostList as $host ) {
				$fullName = $this->buildFullUserName( $dbUser, $host );
				if( !$this->userDefinitelyExists( $dbUser, $host ) ) {
					try{
						$this->db->begin();
						$this->db->query( "CREATE USER $fullName IDENTIFIED BY $escPass", __METHOD__ );
						$this->db->commit();
						$grantableNames[] = $fullName;
					} catch( DBQueryError $dqe ) {
						if( $this->db->lastErrno() == 1396 /* ER_CANNOT_USER */ ) {
							// User (probably) already exists
							$this->db->rollback();
							$status->warning( 'config-install-user-alreadyexists', $dbUser );
							$grantableNames[] = $fullName;
							break;
						} else {
							// If we couldn't create for some bizzare reason and the
							// user probably doesn't exist, skip the grant
							$this->db->rollback();
							$status->warning( 'config-install-user-create-failed', $dbUser, $dqe->getText() );
						}
					}
				} else {
					$status->warning( 'config-install-user-alreadyexists', $dbUser );
					$grantableNames[] = $fullName;
					break;
				}
			}
		}

		// Try to grant to all the users we know exist or we were able to create
		$escPass = $this->db->addQuotes( $password );
		$dbAllTables = $this->db->addIdentifierQuotes( $dbName ) . '.*';
		foreach( $grantableNames as $name ) {
			try {
				$this->db->begin();
				$this->db->query( "GRANT ALL PRIVILEGES ON $dbAllTables TO $name", __METHOD__ );
				$this->db->commit();
			} catch( DBQueryError $dqe ) {
				$this->db->rollback();
				$status->fatal( 'config-install-user-grant-failed', $dbUser, $dqe->getText() );
			}
		}

		return $status;
	}

	/**
	 * Return a formal 'User'@'Host' username for use in queries
	 * @param $name String Username, quotes will be added
	 * @param $host String Hostname, quotes will be added
	 * @return String
	 */
	private function buildFullUserName( $name, $host ) {
		return $this->db->addQuotes( $name ) . '@' . $this->db->addQuotes( $host );
	}

	/**
	 * Try to see if the user account exists. Our "superuser" may not have
	 * access to mysql.user, so false means "no" or "maybe"
	 * @param $host String Hostname to check
	 * @param $user String Username to check
	 * @return boolean
	 */
	private function userDefinitelyExists( $host, $user ) {
		try {
			$res = $this->db->selectRow( 'mysql.user', array( 'Host', 'User' ),
				array( 'Host' => $host, 'User' => $user ), __METHOD__ );
			return (bool)$res;
		} catch( DBQueryError $dqe ) {
			return false;
		}
		
	}

	/**
	 * Return any table options to be applied to all tables that don't
	 * override them.
	 *
	 * @return String
	 */
	protected function getTableOptions() {
		$options = array();
		if ( $this->getVar( '_MysqlEngine' ) !== null ) {
			$options[] = "ENGINE=" . $this->getVar( '_MysqlEngine' );
		}
		if ( $this->getVar( '_MysqlCharset' ) !== null ) {
			$options[] = 'DEFAULT CHARSET=' . $this->getVar( '_MysqlCharset' );
		}
		return implode( ', ', $options );
	}

	/**
	 * Get variables to substitute into tables.sql and the SQL patch files.
	 */
	public function getSchemaVars() {
		return array(
			'wgDBTableOptions' => $this->getTableOptions(),
			'wgDBname' => $this->getVar( 'wgDBname' ),
			'wgDBuser' => $this->getVar( 'wgDBuser' ),
			'wgDBpassword' => $this->getVar( 'wgDBpassword' ),
		);
	}

	public function getLocalSettings() {
		$dbmysql5 = wfBoolToStr( $this->getVar( 'wgDBmysql5', true ) );
		$prefix = LocalSettingsGenerator::escapePhpString( $this->getVar( 'wgDBprefix' ) );
		$tblOpts = LocalSettingsGenerator::escapePhpString( $this->getTableOptions() );
		return
"# MySQL specific settings
\$wgDBprefix         = \"{$prefix}\";

# MySQL table options to use during installation or update
\$wgDBTableOptions   = \"{$tblOpts}\";

# Experimental charset support for MySQL 4.1/5.0.
\$wgDBmysql5 = {$dbmysql5};";
	}
}
