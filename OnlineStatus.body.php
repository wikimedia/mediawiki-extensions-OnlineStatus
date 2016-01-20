<?php

class OnlineStatus {
	/**
	 * Get the user online status
	 *
	 * @param mixed $title string of Title object, if it's a title, if has to be in
	 *                     User: of User_talk: namespace.
	 * @return array ( string status, string username 	) or null
	 */
	static function GetUserStatus( $title, $checkShowPref = false ) {
		if ( is_object( $title ) ) {
			if ( !$title instanceof Title ) {
				return null;
			}

			if ( !in_array( $title->getNamespace(), array( NS_USER, NS_USER_TALK ) ) ) {
				return null;
			}

			$username = explode( '/', $title->getDBkey() );
			$username = $username[0];
		} else {
			$username = $title;
		}

		$user = User::newFromName( $username );

		if ( !$user instanceof User || $user->getId() == 0 ) {
			return null;
		}

		if ( $checkShowPref && !$user->getOption( 'showonline' ) ) {
			return null;
		}

		return array( $user->getOption( 'online' ), $username );
	}

	/**
	 * Used for AJAX requests
	 */
	static function Ajax( $action, $stat = false ) {
		global $wgUser;

		if ( $wgUser->isAnon() ) {
			return wfMessage( 'onlinestatus-js-anon' )->escaped();
		}

		switch ( $action ) {
		case 'get':
			$def = $wgUser->getOption( 'online' );
			$msg = wfMessage( 'onlinestatus-levels' )->inContentLanguage()->plain();
			$lines = explode( "\n", $msg );
			$radios = array();

			foreach ( $lines as $line ) {
				if ( substr( $line, 0, 1 ) != '*' ) {
					continue;
				}

				// For grep. Message keys used here:
				// onlinestatus-toggle-offline, onlinestatus-toggle-online
				$lev = trim( $line, '* ' );
				$radios[] = array(
					$lev,
					wfMessage( 'onlinestatus-toggle-' . $lev )->text(),
					$lev == $def
				);
			}

			return json_encode( $radios );
		case 'set':
			if ( $stat ) {
				$dbw = wfGetDB( DB_MASTER );
				$dbw->startAtomic( __METHOD__ );
				$actual = $wgUser->getOption( 'online' );
				$wgUser->setOption( 'online', $stat );

				if ( $actual != $stat ) {
					$wgUser->getUserPage()->invalidateCache();
					$wgUser->getTalkPage()->invalidateCache();
				}
				$wgUser->saveSettings();
				$wgUser->invalidateCache();
				$dbw->endAtomic( __METHOD__ );

				// For grep. Message keys used here:
				// onlinestatus-toggle-offline, onlinestatus-toggle-online
				return wfMessage(
					'onlinestatus-js-changed',
					wfMessage( 'onlinestatus-toggle-' . $stat )->escaped()
				)->text();
			} else {
				return wfMessage( 'onlinestatus-js-error', $stat )->escaped();
			}
		}
	}

	/**
	 * Hook for ParserFirstCallInit
	 */
	static function ParserFirstCallInit( $parser ) {
		global $wgAllowAnyUserOnlineStatusFunction;

		if ( $wgAllowAnyUserOnlineStatusFunction ) {
			$parser->setFunctionHook( 'anyuseronlinestatus', array( __CLASS__, 'ParserHookCallback' ) );
		}
		return true;
	}

	/**
	 * Callback for {{#anyuserstatus:}}
	 */
	static function ParserHookCallback( &$parser, $user, $raw = false ) {
		$status = self::GetUserStatus( $user );

		if ( $status === null ) {
			return array( 'found' => false );
		}

		if ( empty( $raw ) ) {
			// For grep. Message keys used here:
			// onlinestatus-toggle-offline, onlinestatus-toggle-online
			return wfMessage( 'onlinestatus-toggle-' . $status[0] )->plain();
		} else {
			return $status[0];
		}
	}

	/**
	 * Hook function for MagicWordwgVariableIDs
	 */
	static function MagicWordVariable( &$magicWords ) {
		$magicWords[] = 'onlinestatus_word';
		$magicWords[] = 'onlinestatus_word_raw';

		return true;
	}

	/**
	 * Hook function for ParserGetVariableValueSwitch
	 */
	static function ParserGetVariable( &$parser, &$varCache, &$index, &$ret ) {
		if ( $index == 'onlinestatus_word' ) {
			$status = self::GetUserStatus( $parser->getTitle() );

			if ( $status === null ) {
				return true;
			}

			// For grep. Message keys used here:
			// onlinestatus-toggle-offline, onlinestatus-toggle-online
			$ret = wfMessage( 'onlinestatus-toggle-' . $status[0] )->plain();
			$varCache['onlinestatus'] = $ret;
		} elseif ( $index == 'onlinestatus_word_raw' ) {
			$status = self::GetUserStatus( $parser->getTitle() );

			if ( $status === null ) {
				return true;
			}

			$ret = $status[0];
			$varCache['onlinestatus'] = $ret;
		}

		return true;
	}

	/**
	 * Hook for user preferences
	 */
	public static function GetPreferences( $user, &$preferences ) {
		$msg = wfMessage( 'onlinestatus-levels' )->inContentLanguage()->plain();
		$lines = explode( "\n", $msg );
		$radios = array();

		foreach ( $lines as $line ) {
			if ( substr( $line, 0, 1 ) != '*' ) {
				continue;
			}

			// For grep. Message keys used here:
			// onlinestatus-toggle-offline, onlinestatus-toggle-online
			$lev = trim( $line, '* ' );
			$radios[wfMessage( 'onlinestatus-toggle-' . $lev )->text()] = $lev;
		}

		$preferences['onlineonlogin'] =
			array(
				'type' => 'toggle',
				'section' => 'misc',
				'label-message' => 'onlinestatus-pref-onlineonlogin',
			);

		$preferences['offlineonlogout'] =
			array(
				'type' => 'toggle',
				'section' => 'misc',
				'label-message' => 'onlinestatus-pref-offlineonlogout',
			);

		$prefs = array(
			'online' => array(
				'type' => 'radio',
				'section' => 'personal/info',
				'options' => $radios,
				'label-message' => 'onlinestatus-toggles-desc',
			),
			'showonline' => array(
				'type' => 'check',
				'section' => 'personal/info',
				'label-message' => 'onlinestatus-toggles-show',
				'help-message' => 'onlinestatus-toggles-explain',
			)
		);

		$after = array_key_exists( 'registrationdate', $preferences ) ? 'registrationdate' : 'editcount';
		$preferences = wfArrayInsertAfter( $preferences, $prefs, $after );

		return true;
	}

	/**
	 * Hook for UserLoginComplete
	 */
	static function UserLoginComplete( $user ) {
		if ( $user->getOption( 'onlineonlogin' ) ) {
			$user->setOption( 'online', 'online' );
			$user->saveSettings();
		}

		return true;
	}

	/**
	 * Hook for UserLoginComplete
	 */
	static function UserLogoutComplete( &$newUser, &$injected_html, $oldName = null ) {
		if ( $oldName === null ) {
			return true;
		}

		$oldUser = User::newFromName( $oldName );

		if ( !$oldUser instanceof User ) {
			return true;
		}

		if ( $oldUser->getOption( 'offlineonlogout' ) ) {
			$oldUser->setOption( 'online', 'offline' );
			$oldUser->saveSettings();
		}

		return true;
	}

	/**
	 * Hook function for BeforePageDisplay
	 */
	static function BeforePageDisplay( &$out ) {
		global $wgRequest, $wgUser, $wgUseAjax;

		if ( $wgUser->isLoggedIn() && $wgUseAjax ) {
			$out->addModules( 'ext.onlineStatus' );
		}

		if ( !in_array( $wgRequest->getVal( 'action', 'view' ), array( 'view', 'purge' ) ) ) {
			return true;
		}

		$status = self::GetUserStatus( $out->getTitle(), true );

		if ( $status === null ) {
			return true;
		}

		// For grep. Message keys used here:
		// onlinestatus-subtitle-offline, onlinestatus-subtitle-online
		$out->setSubtitle(
			wfMessage(
				'onlinestatus-subtitle-' . $status[0],
				$status[1]
			)->parseAsBlock()
		);

		return true;
	}

	/**
	 * Hook for PersonalUrls
	 */
	static function PersonalUrls( &$urls, &$title ) {
		global $wgUser, $wgUseAjax;

		# Require ajax
		if ( !$wgUser->isLoggedIn() || !$wgUseAjax || $title->isSpecial( 'Preferences' ) ) {
			return true;
		}

		$arr = array();

		foreach ( $urls as $key => $val ) {
			if ( $key == 'logout' ) {
				$arr['status'] = array(
					'text' => wfMessage( 'onlinestatus-tab' )->escaped(),
					'href' => 'javascript:;',
					'active' => false,
				);
			}

			$arr[$key] = $val;
		}

		$urls = $arr;
		return true;
	}
}
