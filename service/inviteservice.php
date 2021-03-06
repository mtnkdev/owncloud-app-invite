<?php
/**
 * ownCloud - Invitations App
 *
 * @author Lennart Rosam
 * @copyright 2013 MSP Medien Systempartner GmbH & Co. KG <lennart.rosam@medien-systempartner.de>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\Invite\Service;

use \OC_Defaults;
use \OCA\AppFramework\Http\JSONResponse;
use \OCP\Config;

/**
 * This class is the businesslayer of the ownCloud Invitations App.
 *
 * Contains everything required to signup a new user. Including
 * validation, token generation and verification, mailings and
 * of course: adding the user to ownCloud.
 */
class InviteService {
	private $defaults;
	private $config;
	private $api;
	private $l;

	public function __construct($api){
		$this->defaults = new OC_Defaults();
		$this->api = $api;
		$this->l = $api->getTrans();
		$this->config = new Config();
	}

	/**
	 * Creates a random token
	 *
	 * @return A random token as done the password reset feature of ownCloud
	 */
	private function mkToken() {
		$randomBytes = $this->generateRandomBytes(30);
		$salt = $this->config->getSystemValue('passwordsalt', '');
		return hash('sha256', $randomBytes . $salt);
	}

	/**
	 * Checks if the given token is valid for the given user
	 *
	 * @param uid The user id
	 * @param token The token
	 * @return True if the token is valid, otherwise false
	 */
	public function validateToken($uid, $token) {
		return \OC_Preferences::getValue($uid, 'invite', 'token')
				=== hash('sha256', $token);
	}

	/**
	 * Validates the given username
	 *
	 * @param Username The username to validate
	 * @return A validation result
	 */
	public function validateUsername($username='') {

		$result = array(
			'validUsername' => true,
			'msg' => 'OK'
			);

		if(!isset($username) || empty($username)) {
			$result['validUsername'] = false;
			$result['msg'] = $this->l->t('Username is empty');
		}

		if(preg_match( '/[^a-zA-Z0-9 _\.@\-]/', $username )) {
			$result['validUsername'] = false;
			$result['msg'] = $this->l
				->t('Username contains illegal characters');
		}

		if(strlen($username) < 3) {
			$result['validUsername'] = false;
			$result['msg'] = $this->l
				->t('Username must be at least 3 characters long');
		}

		if(\OC_User::userExists($username)) {
			$result['validUsername'] = false;
			$result['msg'] = $this->l->t('User exists already');
		}

		return $result;
	}

	/**
	 * Validates the given email address
	 *
	 * @param email The email to validate
	 * @return A validation result
	 */
	public function validateEmail($email='') {
		$result = array(
			'validEmail' => true,
			'msg' => 'OK'
			);

		if(empty( $email ) || !filter_var( $email, FILTER_VALIDATE_EMAIL)) {
			$result['validEmail'] = false;
			$result['msg'] = $this->l->t('Invalid mail address');
		}

		return $result;
	}

	/**
	 * Checks if the given groups are valid and do exist
	 *
	 * @param groups The group array
	 * @param isAdmin Whether or not the user has admin privileges
	 * @return True if everything is ok, otherwise false
	 */
	public function validateGroups($groups=array(), $isAdmin) {
		// Admins may invite users without setting a group.
		if($isAdmin && (!isset($groups) || count($groups) === 0 )) {
			return true;
		}

		if(!is_array($groups) || count($groups) < 1) {
			return false;
		}

		foreach ($groups as $group) {
				// For now, we don't create new groups!
			if(!\OC_Group::groupExists($group)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validates the given password.
	 *
	 * At the moment, ownCloud does _NOT_ enforce secure
	 * passwords. Anything but an empty password is valid.
	 * Despite ownCloud's default behavior, you bet that
	 * we WILL enforce secure passwords. At least to some
	 * degree.
	 *
	 * The following will be considered a valid password:
	 * - At least 6 characters in length
	 * - Contain at least one upper and one lower case letter
	 * - Contain at least one special character or number
	 *
	 * @param password The password to validate
	 * @return True if the password is valid, otherwise false
	 */
	public function validatePassword($password) {
		$regex = "/(?=^.{6,}$)((?=.*\d)|(?=.*\W+))" .
			"(?![.\n])(?=.*[A-Z])(?=.*[a-z]).*$/";
		return isset($password) && preg_match($regex, $password);
	}

	/**
	 * Chreates the user, adds him to his groups and send him an invite mail.
	 *
	 * This function does double check it's input to make sure that
	 * everything is in order before actually creating anything.
	 *
	 * @param user The user array containing 'username', 'email' and 'groups'
	 * @return The appropriate JSONResponse reporting success or error
	 */
	public function invite($user=array()) {
		// Don't trust the user's input blindly... Validate things first
		$usernameValidation = $this->validateUsername($user['username']);
		$emailValidation = $this->validateEmail($user['email']);
		$uid = $this->api->getUserId();

		// Response model for invalid data
		$invalidDataResponse = array(
			'validUser' => $usernameValidation['validUsername'],
			'validEmail' => $emailValidation['validEmail'],
			'validGroups' => $this->validateGroups(
				$user['groups'],
				$this->api->isAdminUser($uid)
				)
			);

		if(!$usernameValidation['validUsername']
			|| !$emailValidation['validEmail']
			|| !$invalidDataResponse['validGroups']) {
			return new JSONResponse($invalidDataResponse, 400);
		}

		// Set a secure inital password (will not be send to the user)
		$user['password'] = $this->mkToken();

		// Create the user and add him to groups
		if (!\OC_User::createUser($user['username'], $user['password'])) {
			return new JSONResponse(array(
				'msg' => 'User creation failed for '.
				$username .
				'. Please contact your system administrator!'
				), 500);
		}

		if(isset($user['groups']) && is_array($user['groups'])) {
			foreach ($user['groups'] as $group) {
				\OC_Group::addToGroup( $user['username'], $group );
			}
		}

		// Set email and password token
		$token = $this->mkToken();
		\OC_Preferences::setValue(
			$user['username'],
			'settings',
			'email',
			$user['email']
		);

		\OC_Preferences::setValue(
			$user['username'],
			'invite',
			'token',
			hash('sha256', $token) // Hash again for timing attack protection
		);

		// Send email
		$link = \OC_Helper::linkToRoute(
			'invite_join',
			array('user' => $user['username'], 'token' => $token)
		);
		$link = \OC_Helper::makeURLAbsolute($link);
		$tmpl = new \OCP\Template('invite', 'email');
		$tmpl->assign('link', $link);
		$tmpl->assign('inviter', \OC_User::getDisplayName($uid));
		$tmpl->assign('invitee', $user['username']);
		$tmpl->assign('productname', $this->defaults->getName());
		$msg = $tmpl->fetchPage();
		$from = \OC_Preferences::getValue(
			$this->api->getUserId(),
			'settings',
			'email'
		);

		if(!isset($from)) {
			$from = \OCP\Util::getDefaultEmailAddress('invite-noreply');
		}

		try {
			\OC_Mail::send(
				$user['email'],
				$user['username'],
				$this->l->t('You are invited to join %s',
						array($this->defaults->getName())),
				$msg,
				$from,
				\OC_User::getDisplayName($uid)
			);
		} catch (Exception $e) {
			return new JSONResponse(
				array(
					'msg' => 'Error sending email! ' .
							'Please contact your system administrator!',
					'error' => $e
				),
				500
			);
		}

		return new JSONResponse(array('msg' => 'OK'), 200);
	}

	/**
	 * Copied over from ownCloud lib/util.php as from OC 6 on forward,
	 * this wil no longer be accessible.
	 *
	 * @brief Generates a cryptographical secure pseudorandom string
	 * @param Int with the length of the random string
	 * @return String
	 * Please also update secureRNG_available if you change something here
	 */
	private function generateRandomBytes($length = 30) {

		// Try to use openssl_random_pseudo_bytes
		if(function_exists('openssl_random_pseudo_bytes')) {
			$pseudo_byte = bin2hex(
				openssl_random_pseudo_bytes($length, $strong)
			);
			if($strong == true) {
				 // Truncate it to match the length
				return substr($pseudo_byte, 0, $length);
			}
		}

		// Try to use /dev/urandom
		$fp = @file_get_contents('/dev/urandom', false, null, 0, $length);
		if ($fp !== false) {
			$string = substr(bin2hex($fp), 0, $length);
			return $string;
		}

		// Fallback to mt_rand()
		$characters = '0123456789';
		$characters .= 'abcdefghijklmnopqrstuvwxyz';
		$charactersLength = strlen($characters)-1;
		$pseudo_byte = "";

		// Select some random characters
		for ($i = 0; $i < $length; $i++) {
			$pseudo_byte .= $characters[mt_rand(0, $charactersLength)];
		}
		return $pseudo_byte;
	}

}