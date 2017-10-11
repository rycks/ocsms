<?php
use \OCA\OcSms\Lib\CountryCodes;

\OCP\Util::addScript('ocsms', 'angular/angular.min');
\OCP\Util::addScript('ocsms', 'public/app');
\OCP\Util::addStyle('ocsms', 'style');
?>

<div class="ng-scope" id="app" ng-app="OcSms" ng-controller="OcSmsController">
	<div id="app-mailbox-peers">
		<div id="app-contacts-loader" class="icon-loading" ng-show="isContactsLoading">
		</div>
		<ul class="contact-list" ng-show="!isContactsLoading">
			<li ng-repeat="contact in contacts | orderBy:setting_contactOrder:setting_contactOrderReverse" peer-label="{{ contact.label }}" ng-click="loadConversation(contact);" href="#">
				<img class="ocsms-plavatar" ng-src="{{ contact.avatar }}" ng-show="contact.avatar !== undefined" />
				<div class="ocsms-plavatar" ng-show="contact.avatar === undefined" ng-style="{'background-color': (contact.uid | peerColor)}">{{ contact.label | firstCharacter }}</div>
				<a class="ocsms-plname" style="{{ contact.unread > 0 ? 'font-weight:bold;' : ''}}" mailbox-label="{{ contact.label }}" mailbox-navigation="{{ contact.nav }}">{{ contact.label }}{{ contact.unread > 0 ? ' (' + contact.unread + ') ' : '' }}</a>
			</li>
		</ul>
	</div>
	<div id="app-settings" class="ng-scope">
		<div id="app-settings-header">
			<button name="app settings" class="settings-button" data-apps-slide-toggle="#app-settings-content"></button>
		</div>
		<div id="app-settings-content">
			<div><label for="setting_msg_per_page">Max messages on tab loading</label>
				<input type="number" min="10" max="10000" name="setting_msg_per_page" ng-model="setting_msgLimit" ng-change="setMessageLimit()" to-int />
				<span class="label-invalid-input" ng-if="setting_msgLimit == null || setting_msgLimit == undefined">Invalid message limit</span>
			</div>

			<div><label for="intl_phone">Country code</label>
				<select name="intl_phone" id="sel_intl_phone">
				<?php foreach (CountryCodes::$codes as $code => $cval) { ?>
					<option><?php p($code); ?></option>
				<?php } ?>
				</select>
				<button class="new-button primary icon-checkmark-white" ng-click="sendCountry();"></button>
			</div>

			<div>
				<label for="setting_contact_order">Contact ordering</label>
				<select name="setting_contact_order" ng-model="setting_contactOrder" ng-change="setContactOrderSetting()">
					<option value="lastmsg">Last message</option>
					<option value="label">Label</option>
				</select>
				<label for "setting_contact_order_reverse">Reverse ?</label>
				<input type="checkbox" ng-model="setting_contactOrderReverse" ng-change="setContactOrderSetting()" />
			</div>

			<div>
				<label for"setting_notif">Notification settings</label>
				<select name="setting_notif" ng-model="setting_enableNotifications" ng-change="setNotificationSetting()">
					<option value="1">Enable</option>
					<option value="0">Disable</option>
				</select>
			</div>

			<div>
				<label for"setting_forwardmail">Forward by mail settings</label>
				<input type="checkbox" ng-model="setting_forwardmail" ng-change="setForwardmailSetting()" />
			</div>

		</div> <!-- app-settings-content -->
	</div>

	<div id="app-content">
		<div id="app-content-loader" class="icon-loading" ng-show="isConvLoading">
		</div>
		<div id="app-content-header" ng-show="!isConvLoading && selectedContact.label !== undefined && selectedContact.label !== ''"
			 ng-style="{'background-color': (selectedContact.uid | peerColor)}">
			<div id="ocsms-contact-avatar">
				<img class="ocsms-plavatar-big" ng-src="{{ selectedContact.avatar }}"
					 ng-show="selectedContact.avatar !== undefined" />
			</div>
			<div id="ocsms-contact-details">
				<div id="ocsms-phone-label">{{ selectedContact.label }} </div>
				<div id="ocsms-phone-opt-number">{{ selectedContact.opt_numbers }}</div>
				<div id="ocsms-phone-msg-nb">{{ messages.length }} message(s) shown. {{ totalMessageCount }} message(s) stored in database.</div>
			</div>
			<div id="ocsms-contact-actions">
				<div id="ocsms-conversation-removal" class="icon-delete icon-delete-white svn delete action" ng-click="removeConversation();"></div>
			</div>

		</div>
		<div id="app-content-wrapper" ng-show="!isConvLoading">
			<div ng-show="messages.length == 0" id="ocsms-empty-conversation">Please choose a conversation on the left menu</div>
			<div ng-show="messages.length > 0">
				<div ng-repeat="message in messages | orderBy:'date'">
					<div class="msg-{{ message.type }}">
						<div>{{ message.content }}</div>
						<div style="display: block;" id="ocsms-message-removal" class="icon-delete svn delete action" ng-click="removeConversationMessage(message.id);"></div>
						<div class="msg-date">{{ message.date | date:'medium' }}</div>
					</div>
					<div class="msg-spacer"></div>
				</div>
				<div id="searchresults"></div>
			</div>
		</div>
	</div>
</div>
