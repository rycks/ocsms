<?php
/**
 * NextCloud - Phone Sync
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Loic Blot <loic.blot@unix-experience.fr>
 * @copyright Loic Blot 2014-2017
 * @author Eric Seigne <eric.seigne@cap-rel.fr>
 * @copyright Eric Seigne 2017
 */

namespace OCA\OcSms\Db;

use \OCP\IDBConnection;

use \OCP\AppFramework\Db\Mapper;

use \OCA\OcSms\AppInfo\OcSmsApp;
use \OCA\OcSms\Lib\PhoneNumberFormatter;
use \OCA\OcSms\Db\ConversationStateMapper;

use \OCP\IUserSession;
use \OCP\Mail\IMailer;
use \OCP\IUser;
use \OCP\IUserManager;

class SmsMapper extends Mapper {
	/*
	* Useful to use a tag for getAllIds, else mobile can have problem to know
	* on which mailbox it works
	*/
	private static $mailboxNames = array(0 => "inbox", 1 => "sent", 2 => "drafts");
	private static $messageTypes = array(
		0 => "all", 1 => "inbox",
		2 => "sent", 3 => "drafts",
		4 => "outbox", 5 => "failed",
		6 => "queued"
	);
	private $convStateMapper;

	public function __construct (IDBConnection $db, ConversationStateMapper $cmapper) {
		parent::__construct($db, 'ocsms_smsdatas');
		$this->convStateMapper = $cmapper;
	}

	public function getAllIds ($userId) {
		$query = \OCP\DB::prepare('SELECT sms_id, sms_mailbox FROM ' .
			'*PREFIX*ocsms_smsdatas WHERE user_id = ?');
		$result = $query->execute(array($userId));

		$smsList = array();
		while($row = $result->fetchRow()) {
			// This case may not arrive, but we test if the DB is consistent
			if (!in_array($row["sms_mailbox"], SmsMapper::$mailboxNames)) {
				continue;
			}
			$mbox = SmsMapper::$mailboxNames[$row["sms_mailbox"]];
			if (!isset($smsList[$mbox])) {
				$smsList[$mbox] = array();
			}

			if (!in_array($row["sms_id"], $smsList[$mbox])) {
				array_push($smsList[$mbox], $row["sms_id"]);
			}
		}
		return $smsList;
	}

	public function getLastTimestamp ($userId) {
		$query = \OCP\DB::prepare('SELECT max(sms_date) as mx FROM ' .
			'*PREFIX*ocsms_smsdatas WHERE user_id = ?');
		$result = $query->execute(array($userId));

		if ($row = $result->fetchRow()) {
			return $row["mx"];
		}

		return 0;
	}

	public function getLastTimestampMail ($userId) {
		$query = \OCP\DB::prepare('SELECT datavalue as mx FROM ' .
			'*PREFIX*ocsms_user_datas WHERE user_id = ? AND datakey = ?');
		$result = $query->execute(array($userId, 'mail_date_last'));

		if ($row = $result->fetchRow()) {
			return $row["mx"];
		}

		return 0;
	}

    public function updateLastTimestampMail ($userId, $date) {
        $lastmail = self::getLastTimestampMail ($userId);

        //INSERT the first time
        if($lastmail == 0) {
            $query = \OCP\DB::prepare('INSERT INTO *PREFIX*ocsms_user_datas ' .
			'(user_id, datakey, datavalue) VALUES ' .
			'(?,?,?)');
			$result = $query->execute(array(
				$userId, 'mail_date_last', $date
			));
        }
        //OR UPDATE anywhere :)
        else {
            $query = \OCP\DB::prepare('UPDATE *PREFIX*ocsms_user_datas SET datavalue=? WHERE user_id=? AND datakey=?');
            $result = $query->execute(array(
				$date, $userId, 'mail_date_last'
			));
        }
		return 0;
	}

	public function getAllPhoneNumbers ($userId) {
		$query = \OCP\DB::prepare('SELECT sms_address FROM ' .
		'*PREFIX*ocsms_smsdatas WHERE user_id = ? AND sms_mailbox IN (?,?)');
		$result = $query->execute(array($userId, 0, 1));

		$phoneList = array();
		while($row = $result->fetchRow()) {
			$pn = $row["sms_address"];
			if (!in_array($pn, $phoneList)) {
				array_push($phoneList, $pn);
			}
		}
		return $phoneList;
	}

	/*
	 *	get all possible SMS_adresses for a given formated phonenumber
	 */
	public function getAllPhoneNumbersForFPN ($userId, $phoneNumber, $country) {
		$query = \OCP\DB::prepare('SELECT sms_address FROM ' .
		'*PREFIX*ocsms_smsdatas WHERE user_id = ? AND sms_mailbox IN (?,?)');
		$result = $query->execute(array($userId, 0, 1));
		$phoneList = array();
		while($row = $result->fetchRow()) {
			$pn = $row["sms_address"];
			$fmtPN = PhoneNumberFormatter::format($country, $pn);
			if (!isset($phoneList[$fmtPN])) {
				$phoneList[$fmtPN] = array();
			}
			if(!isset($phoneList[$fmtPN][$pn])) {
				$phoneList[$fmtPN][$pn] = 0;
			}
			$phoneList[$fmtPN][$pn] += 1;
		}
		$fpn = PhoneNumberFormatter::format($country, $phoneNumber);
		if(isset($phoneList[$fpn])){
			return $phoneList[$fpn];
		}
		else {
			return array();
		}
	}

	public function getAllMessagesForPhoneNumber ($userId, $phoneNumber, $country, $minDate = 0) {

		$phlst = $this->getAllPhoneNumbersForFPN($userId, $phoneNumber, $country);
		$messageList = array();
		$query = \OCP\DB::prepare('SELECT sms_date, sms_msg, sms_type FROM ' .
		'*PREFIX*ocsms_smsdatas WHERE user_id = ? AND sms_address = ? ' .
		'AND sms_mailbox IN (?,?) AND sms_date > ?');

		foreach ($phlst as $pn => $val) {
			$result = $query->execute(array($userId, $pn, 0, 1, $minDate));

			while ($row = $result->fetchRow()) {
				$messageList[$row["sms_date"]] = array(
					"msg" =>  $row["sms_msg"],
					"type" => $row["sms_type"]
				);
			}
		}
		return $messageList;
	}

	public function getMessageCount ($userId) {
		$query = \OCP\DB::prepare('SELECT count(*) AS count FROM ' .
			'*PREFIX*ocsms_smsdatas WHERE user_id = ?');
		$result = $query->execute(array($userId));
		if ($row = $result->fetchRow()) {
			return $row["count"];
		}

		return 0;
	}

	public function getMessages ($userId, $start, $limit) {
		$messageList = array();

		$query = \OCP\DB::prepare('SELECT sms_address, sms_date, sms_msg, sms_type, sms_mailbox FROM ' .
			'*PREFIX*ocsms_smsdatas WHERE user_id = ? AND sms_date > ? ORDER BY sms_date', (int) $limit);
		$result = $query->execute(array($userId, $start));
		while ($row = $result->fetchRow()) {
			$messageList[$row["sms_date"]] = array(
				"address" => $row["sms_address"],
				"mailbox" => $row["sms_mailbox"],
				"msg" => $row["sms_msg"],
				"type" => $row["sms_type"]
			);
		}
		return $messageList;
	}

	public function countMessagesForPhoneNumber ($userId, $phoneNumber, $country) {
		$cnt = 0;
		$phlst = $this->getAllPhoneNumbersForFPN ($userId, $phoneNumber, $country);

		$query = \OCP\DB::prepare('SELECT count(*) as ct FROM ' .
		'*PREFIX*ocsms_smsdatas WHERE user_id = ? AND sms_address = ? ' .
		'AND sms_mailbox IN (?,?)');

		foreach($phlst as $pn => $val) {
			$result = $query->execute(array($userId, $pn, 0, 1));
			if ($row = $result->fetchRow())
				$cnt += $row["ct"];
		}
		return $cnt;
	}

	public function removeMessagesForPhoneNumber ($userId, $phoneNumber) {
		\OCP\DB::beginTransaction();
		$query = \OCP\DB::prepare('DELETE FROM ' .
		'*PREFIX*ocsms_smsdatas WHERE user_id = ? AND sms_address = ?');
		$query->execute(array($userId, $phoneNumber));
		\OCP\DB::commit();
	}

	/*
	* WARN: messageId is sms_date here
	*/
	public function removeMessage ($userId, $phoneNumber, $messageId)  {
		\OCP\DB::beginTransaction();
		$query = \OCP\DB::prepare('DELETE FROM ' .
		'*PREFIX*ocsms_smsdatas WHERE user_id = ? AND sms_address = ? ' .
		'AND sms_date = ?');
		$query->execute(array($userId, $phoneNumber, $messageId));
		\OCP\DB::commit();
	}

	public function getLastMessageTimestampForAllPhonesNumbers ($userId, $order = true) {
		$sql = 'SELECT sms_address, MAX(sms_date) AS mx FROM ' .
		'*PREFIX*ocsms_smsdatas WHERE user_id = ? AND sms_mailbox IN (?,?) ' .
		'GROUP BY sms_address';

		if ($order === true) {
			$sql .= ' ORDER BY mx DESC';
		}

		$query = \OCP\DB::prepare($sql);
		$result = $query->execute(array($userId, 0, 1));

		$phoneList = array();
		while ($row = $result->fetchRow()) {
			$phoneNumber = preg_replace("#[ ]#", "", $row["sms_address"]);
			if (!array_key_exists($phoneNumber, $phoneList)) {
				$phoneList[$phoneNumber] = $row["mx"];
			}
			// Maybe duplicate due to spaces in database
			else if ($phoneList[$phoneNumber] < $row["mx"]) {
				$phoneList[$phoneNumber] = $row["mx"];
			}
		}
		return $phoneList;
	}

	public function getNewMessagesCountForAllPhonesNumbers($userId, $lastDate) {
		$ld = ($lastDate == '') ? 0 : $lastDate;

		$sql = 'SELECT sms_address, COUNT(sms_date) AS ct FROM ' .
		'*PREFIX*ocsms_smsdatas WHERE user_id = ? AND sms_mailbox IN (?,?) ' .
		'AND sms_date > ? GROUP BY sms_address';

		$query = \OCP\DB::prepare($sql);
		$result = $query->execute(array($userId, 0, 1, $ld));

		$phoneList = array();
		while ($row = $result->fetchRow()) {
			$phoneNumber = preg_replace("#[ ]#", "", $row["sms_address"]);
			if ($this->convStateMapper->getLastForPhoneNumber($userId, $phoneNumber) < $lastDate) {
				if (!array_key_exists($phoneNumber, $phoneList)) {
					$phoneList[$phoneNumber] = $row["ct"];
				}
				else {
					$phoneList[$phoneNumber] += $row["ct"];
				}
			}
		}
		return $phoneList;
	}

	public function writeToDB ($userId, $smsList, $purgeAllSmsBeforeInsert = false) {
		\OCP\DB::beginTransaction();

		if ($purgeAllSmsBeforeInsert === true) {
			$query = \OCP\DB::prepare('DELETE FROM *PREFIX*ocsms_smsdatas ' .
			'WHERE user_id = ?');
			$result = $query->execute(array($userId));
		}

		foreach ($smsList as $sms) {
			$smsFlags = sprintf("%s%s",
				$sms["read"] === "true" ? "1" : "0",
				$sms["seen"] === "true" ? "1" : "0"
			);

			// Only delete if we haven't purged the DB
			if ($purgeAllSmsBeforeInsert === false) {
				// Remove previous record
				// @ TODO: only update the required fields, getAllIds can be useful
				$query = \OCP\DB::prepare('DELETE FROM *PREFIX*ocsms_smsdatas ' .
				'WHERE user_id = ? AND sms_id = ?');
				$result = $query->execute(array(
					$userId, (int) $sms["_id"]
				));
			}
			$now = date("Y-m-d H:i:s");
			$query = \OCP\DB::prepare('INSERT INTO *PREFIX*ocsms_smsdatas ' .
			'(user_id, added, lastmodified, sms_flags, sms_date, sms_id,' .
			'sms_address, sms_msg, sms_mailbox, sms_type) VALUES ' .
			'(?,?,?,?,?,?,?,?,?,?)');
			$result = $query->execute(array(
				$userId, $now, $now, $smsFlags,
				$sms["date"], (int) $sms["_id"],
				$sms["address"], $sms["body"], (int) $sms["mbox"],
				(int) $sms["type"]
			));
        }
		\OCP\DB::commit();
		
		//Send mail if checkbox is toggled -> i don't know how to get that ?
		\OCP\DB::beginTransaction();
		//search for last mail and last sms
		$lastsms  = self::getLastTimestamp($userId);
		$lastmail = self::getLastTimestampMail($userId);
		$user = \OC::$server->getUserManager()->get($userId);
		$emailAddress = $user->getEMailAddress();

        if($emailAddress != "") {
            /*
              $fh = fopen("/tmp/eric.log", 'a');
              fwrite($fh,"***** sms : ($userId)");
              fwrite($fh, "<" . $emailAddress . ">");
              fwrite($fh, "<" . $emailAddress2 . ">");
              fwrite($fh, $sms["body"]);
              fwrite($fh, "*****\n");
              fclose($fh);
            */        
            //prepare for mail
            $from_user = "Robot SMS - " . $sms["address"];
            $headers = "From: $from_user <$emailAddress>\r\n".
                "MIME-Version: 1.0" . "\r\n" .
                "Content-type: text/plain; charset=UTF-8" . "\r\n"; 
            
            if($lastsms > $lastmail) {
                $message = "New SMS form $userId.\nFrom: " . $sms["address"] . "\nMessage:\n\n" . $sms["body"] . "\n\n--\nBy smsmapper.php (nextcloud modifyed by erics)";
                mail($emailAddress, "New SMS from " . $sms["address"], $message, $headers);
            }
            self::updateLastTimestampMail ($userId, $lastsms);
            \OCP\DB::commit();
        }
    }
}


?>
