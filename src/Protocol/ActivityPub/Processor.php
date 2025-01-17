<?php
/**
 * @file src/Protocol/ActivityPub/Processor.php
 */
namespace Friendica\Protocol\ActivityPub;

use Friendica\Database\DBA;
use Friendica\Content\Text\BBCode;
use Friendica\Content\Text\HTML;
use Friendica\Core\Config;
use Friendica\Core\PConfig;
use Friendica\Core\Logger;
use Friendica\Core\Protocol;
use Friendica\Model\Contact;
use Friendica\Model\APContact;
use Friendica\Model\Item;
use Friendica\Model\Event;
use Friendica\Model\Term;
use Friendica\Model\User;
use Friendica\Model\Mail;
use Friendica\Protocol\ActivityPub;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\JsonLD;
use Friendica\Util\Strings;

/**
 * ActivityPub Processor Protocol class
 */
class Processor
{
	/**
	 * Converts mentions from Pleroma into the Friendica format
	 *
	 * @param string $body
	 *
	 * @return string converted body
	 */
	private static function convertMentions($body)
	{
		$URLSearchString = "^\[\]";
		$body = preg_replace("/\[url\=([$URLSearchString]*)\]([#@!])(.*?)\[\/url\]/ism", '$2[url=$1]$3[/url]', $body);

		return $body;
	}

	/**
	 * Replaces emojis in the body
	 *
	 * @param array $emojis
	 * @param string $body
	 *
	 * @return string with replaced emojis
	 */
	private static function replaceEmojis($body, array $emojis)
	{
		foreach ($emojis as $emoji) {
			$replace = '[class=emoji mastodon][img=' . $emoji['href'] . ']' . $emoji['name'] . '[/img][/class]';
			$body = str_replace($emoji['name'], $replace, $body);
		}
		return $body;
	}

	/**
	 * Constructs a string with tags for a given tag array
	 *
	 * @param array   $tags
	 * @param boolean $sensitive
	 * @return string with tags
	 */
	private static function constructTagString(array $tags = null, $sensitive = false)
	{
		if (empty($tags)) {
			return '';
		}

		$tag_text = '';
		foreach ($tags as $tag) {
			if (in_array(defaults($tag, 'type', ''), ['Mention', 'Hashtag'])) {
				if (!empty($tag_text)) {
					$tag_text .= ',';
				}

				$tag_text .= substr($tag['name'], 0, 1) . '[url=' . $tag['href'] . ']' . substr($tag['name'], 1) . '[/url]';
			}
		}

		/// @todo add nsfw for $sensitive

		return $tag_text;
	}

	/**
	 * Add attachment data to the item array
	 *
	 * @param array   $attachments
	 * @param array   $item
	 * @param boolean $no_images
	 *
	 * @return array array
	 */
	private static function constructAttachList($attachments, $item, $no_images)
	{
		if (empty($attachments)) {
			return $item;
		}

		foreach ($attachments as $attach) {
			$filetype = strtolower(substr($attach['mediaType'], 0, strpos($attach['mediaType'], '/')));
			if ($filetype == 'image') {
				if ($no_images) {
					continue;
				}

				$item['body'] .= "\n[img]" . $attach['url'] . '[/img]';
			} else {
				if (!empty($item["attach"])) {
					$item["attach"] .= ',';
				} else {
					$item["attach"] = '';
				}
				if (!isset($attach['length'])) {
					$attach['length'] = "0";
				}
				$item["attach"] .= '[attach]href="'.$attach['url'].'" length="'.$attach['length'].'" type="'.$attach['mediaType'].'" title="'.defaults($attach, 'name', '').'"[/attach]';
			}
		}

		return $item;
	}

	/**
	 * Updates a message
	 *
	 * @param array $activity Activity array
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function updateItem($activity)
	{
		$item = Item::selectFirst(['uri', 'thr-parent', 'gravity'], ['uri' => $activity['id']]);
		if (!DBA::isResult($item)) {
			Logger::warning('Unknown item', ['uri' => $activity['id']]);
			return;
		}

		$item['changed'] = DateTimeFormat::utcNow();
		$item['edited'] = DateTimeFormat::utc($activity['updated']);

		$item = self::processContent($activity, $item);
		if (empty($item)) {
			return;
		}

		Item::update($item, ['uri' => $activity['id']]);
	}

	/**
	 * Prepares data for a message
	 *
	 * @param array $activity Activity array
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function createItem($activity)
	{
		$item = [];
		$item['verb'] = ACTIVITY_POST;
		$item['thr-parent'] = $activity['reply-to-id'];

		if ($activity['reply-to-id'] == $activity['id']) {
			$item['gravity'] = GRAVITY_PARENT;
			$item['object-type'] = ACTIVITY_OBJ_NOTE;
		} else {
			$item['gravity'] = GRAVITY_COMMENT;
			$item['object-type'] = ACTIVITY_OBJ_COMMENT;
		}

		if (empty($activity['directmessage']) && ($activity['id'] != $activity['reply-to-id']) && !Item::exists(['uri' => $activity['reply-to-id']])) {
			Logger::log('Parent ' . $activity['reply-to-id'] . ' not found. Try to refetch it.');
			self::fetchMissingActivity($activity['reply-to-id'], $activity);
		}

		$item['diaspora_signed_text'] = defaults($activity, 'diaspora:comment', '');

		self::postItem($activity, $item);
	}

	/**
	 * Delete items
	 *
	 * @param array $activity
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function deleteItem($activity)
	{
		$owner = Contact::getIdForURL($activity['actor']);

		Logger::log('Deleting item ' . $activity['object_id'] . ' from ' . $owner, Logger::DEBUG);
		Item::delete(['uri' => $activity['object_id'], 'owner-id' => $owner]);
	}

	/**
	 * Prepare the item array for an activity
	 *
	 * @param array $activity Activity array
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function addTag($activity)
	{
		if (empty($activity['object_content']) || empty($activity['object_id'])) {
			return;
		}

		foreach ($activity['receiver'] as $receiver) {
			$item = Item::selectFirst(['id', 'tag', 'origin', 'author-link'], ['uri' => $activity['target_id'], 'uid' => $receiver]);
			if (!DBA::isResult($item)) {
				// We don't fetch missing content for this purpose
				continue;
			}

			if (($item['author-link'] != $activity['actor']) && !$item['origin']) {
				Logger::info('Not origin, not from the author, skipping update', ['id' => $item['id'], 'author' => $item['author-link'], 'actor' => $activity['actor']]);
				continue;
			}

			// To-Do:
			// - Check if "blocktag" is set
			// - Check if actor is a contact

			if (!stristr($item['tag'], trim($activity['object_content']))) {
				$tag = $item['tag'] . (strlen($item['tag']) ? ',' : '') . '#[url=' . $activity['object_id'] . ']'. $activity['object_content'] . '[/url]';
				Item::update(['tag' => $tag], ['id' => $item['id']]);
				Logger::info('Tagged item', ['id' => $item['id'], 'tag' => $activity['object_content'], 'uri' => $activity['target_id'], 'actor' => $activity['actor']]);
			}
		}
	}

	/**
	 * Prepare the item array for an activity
	 *
	 * @param array  $activity Activity array
	 * @param string $verb     Activity verb
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function createActivity($activity, $verb)
	{
		$item = [];
		$item['verb'] = $verb;
		$item['thr-parent'] = $activity['object_id'];
		$item['gravity'] = GRAVITY_ACTIVITY;
		$item['object-type'] = ACTIVITY_OBJ_NOTE;

		$item['diaspora_signed_text'] = defaults($activity, 'diaspora:like', '');

		self::postItem($activity, $item);
	}

	/**
	 * Create an event
	 *
	 * @param array $activity Activity array
	 * @param array $item
	 * @throws \Exception
	 */
	public static function createEvent($activity, $item)
	{
		$event['summary']  = HTML::toBBCode($activity['name']);
		$event['desc']     = HTML::toBBCode($activity['content']);
		$event['start']    = $activity['start-time'];
		$event['finish']   = $activity['end-time'];
		$event['nofinish'] = empty($event['finish']);
		$event['location'] = $activity['location'];
		$event['adjust']   = true;
		$event['cid']      = $item['contact-id'];
		$event['uid']      = $item['uid'];
		$event['uri']      = $item['uri'];
		$event['edited']   = $item['edited'];
		$event['private']  = $item['private'];
		$event['guid']     = $item['guid'];
		$event['plink']    = $item['plink'];

		$condition = ['uri' => $item['uri'], 'uid' => $item['uid']];
		$ev = DBA::selectFirst('event', ['id'], $condition);
		if (DBA::isResult($ev)) {
			$event['id'] = $ev['id'];
		}

		$event_id = Event::store($event);
		Logger::log('Event '.$event_id.' was stored', Logger::DEBUG);
	}

	/**
	 * Process the content
	 *
	 * @param array $activity Activity array
	 * @param array $item
	 * @return array|bool Returns the item array or false if there was an unexpected occurrence
	 * @throws \Exception
	 */
	private static function processContent($activity, $item)
	{
		$item['title'] = HTML::toBBCode($activity['name']);

		if (!empty($activity['source'])) {
			$item['body'] = $activity['source'];
		} else {
			$content = HTML::toBBCode($activity['content']);

			if (!empty($activity['emojis'])) {
				$content = self::replaceEmojis($content, $activity['emojis']);
			}

			$content = self::convertMentions($content);

			if (empty($activity['directmessage']) && ($item['thr-parent'] != $item['uri']) && ($item['gravity'] == GRAVITY_COMMENT)) {
				$item_private = !in_array(0, $activity['item_receiver']);
				$parent = Item::selectFirst(['id', 'private', 'author-link', 'alias'], ['uri' => $item['thr-parent']]);
				if (!DBA::isResult($parent)) {
					Logger::warning('Unknown parent item.', ['uri' => $item['thr-parent']]);
					return false;
				}
				if ($item_private && !$parent['private']) {
					Logger::warning('Item is private but the parent is not. Dropping.', ['item-uri' => $item['uri'], 'thr-parent' => $item['thr-parent']]);
					return false;
				}

				$potential_implicit_mentions = self::getImplicitMentionList($parent);
				$content = self::removeImplicitMentionsFromBody($content, $potential_implicit_mentions);
				$activity['tags'] = self::convertImplicitMentionsInTags($activity['tags'], $potential_implicit_mentions);
			}
			$item['content-warning'] = HTML::toBBCode($activity['summary']);
			$item['body'] = $content;

			if (($activity['object_type'] == 'as:Video') && !empty($activity['alternate-url'])) {
				$item['body'] .= "\n[video]" . $activity['alternate-url'] . '[/video]';
			}
		}

		$item['tag'] = self::constructTagString($activity['tags'], $activity['sensitive']);

		$item['location'] = $activity['location'];

		if (!empty($item['latitude']) && !empty($item['longitude'])) {
			$item['coord'] = $item['latitude'] . ' ' . $item['longitude'];
		}

		$item['app'] = $activity['generator'];

		return $item;
	}

	/**
	 * Creates an item post
	 *
	 * @param array $activity Activity data
	 * @param array $item     item array
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private static function postItem($activity, $item)
	{
		/// @todo What to do with $activity['context']?
		if (empty($activity['directmessage']) && ($item['gravity'] != GRAVITY_PARENT) && !Item::exists(['uri' => $item['thr-parent']])) {
			Logger::info('Parent not found, message will be discarded.', ['thr-parent' => $item['thr-parent']]);
			return;
		}

		$item['network'] = Protocol::ACTIVITYPUB;
		$item['private'] = !in_array(0, $activity['receiver']);
		$item['author-link'] = $activity['author'];
		$item['author-id'] = Contact::getIdForURL($activity['author'], 0, true);
		$item['owner-link'] = $activity['actor'];
		$item['owner-id'] = Contact::getIdForURL($activity['actor'], 0, true);

		$isForum = false;

		if (!empty($activity['thread-completion'])) {
			// Store the original actor in the "causer" fields to enable the check for ignored or blocked contacts
			$item['causer-link'] = $item['owner-link'];
			$item['causer-id'] = $item['owner-id'];

			Logger::info('Ignoring actor because of thread completion.', ['actor' => $item['owner-link']]);
			$item['owner-link'] = $item['author-link'];
			$item['owner-id'] = $item['author-id'];
		} else {
			$actor = APContact::getByURL($item['owner-link'], false);
			$isForum = ($actor['type'] == 'Group');
		}

		$item['uri'] = $activity['id'];

		$item['created'] = DateTimeFormat::utc($activity['published']);
		$item['edited'] = DateTimeFormat::utc($activity['updated']);
		$item['guid'] = $activity['diaspora:guid'];

		$item = self::processContent($activity, $item);
		if (empty($item)) {
			return;
		}

		$item['plink'] = defaults($activity, 'alternate-url', $item['uri']);

		$item = self::constructAttachList($activity['attachments'], $item, !empty($activity['source']));

		$stored = false;

		foreach ($activity['receiver'] as $receiver) {
			$item['uid'] = $receiver;

			if ($isForum) {
				$item['contact-id'] = Contact::getIdForURL($activity['actor'], $receiver, true);
			}

			if (empty($item['contact-id'])) {
				$item['contact-id'] = Contact::getIdForURL($activity['author'], $receiver, true);
			}

			if (($receiver != 0) && empty($item['contact-id'])) {
				$item['contact-id'] = Contact::getIdForURL($activity['author'], 0, true);
			}

			if (!empty($activity['directmessage'])) {
				self::postMail($activity, $item);
				continue;
			}

			if (PConfig::get($receiver, 'system', 'accept_only_sharer', false) && ($receiver != 0) && ($item['gravity'] == GRAVITY_PARENT)) {
				$skip = !Contact::isSharingByURL($activity['author'], $receiver);

				if ($skip && (($activity['type'] == 'as:Announce') || $isForum)) {
					$skip = !Contact::isSharingByURL($activity['actor'], $receiver);
				}

				if ($skip) {
					Logger::info('Skipping post', ['uid' => $receiver, 'url' => $item['uri']]);
					continue;
				}

				Logger::info('Accepting post', ['uid' => $receiver, 'url' => $item['uri']]);
			}

			if ($activity['object_type'] == 'as:Event') {
				self::createEvent($activity, $item);
			}

			$item_id = Item::insert($item);
			if ($item_id) {
				Logger::info('Item insertion successful', ['user' => $item['uid'], 'item_id' => $item_id]);
			} else {
				Logger::notice('Item insertion aborted', ['user' => $item['uid']]);
			}

			if ($item['uid'] == 0) {
				$stored = $item_id;
			}
		}

		// Store send a follow request for every reshare - but only when the item had been stored
		if ($stored && !$item['private'] && ($item['gravity'] == GRAVITY_PARENT) && ($item['author-link'] != $item['owner-link'])) {
			$author = APContact::getByURL($item['owner-link'], false);
			// We send automatic follow requests for reshared messages. (We don't need though for forum posts)
			if ($author['type'] != 'Group') {
				Logger::log('Send follow request for ' . $item['uri'] . ' (' . $stored . ') to ' . $item['author-link'], Logger::DEBUG);
				ActivityPub\Transmitter::sendFollowObject($item['uri'], $item['author-link']);
			}
		}
	}

	/**
	 * Creates an mail post
	 *
	 * @param array $activity Activity data
	 * @param array $item     item array
	 * @return int|bool New mail table row id or false on error
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	private static function postMail($activity, $item)
	{
		if (($item['gravity'] != GRAVITY_PARENT) && !DBA::exists('mail', ['uri' => $item['thr-parent'], 'uid' => $item['uid']])) {
			Logger::info('Parent not found, mail will be discarded.', ['uid' => $item['uid'], 'uri' => $item['thr-parent']]);
			return false;
		}

		Logger::info('Direct Message', $item);

		$msg = [];
		$msg['uid'] = $item['uid'];

		$msg['contact-id'] = $item['contact-id'];

		$contact = Contact::getById($item['contact-id'], ['name', 'url', 'photo']);
		$msg['from-name'] = $contact['name'];
		$msg['from-url'] = $contact['url'];
		$msg['from-photo'] = $contact['photo'];

		$msg['uri'] = $item['uri'];
		$msg['created'] = $item['created'];

		$parent = DBA::selectFirst('mail', ['parent-uri', 'title'], ['uri' => $item['thr-parent']]);
		if (DBA::isResult($parent)) {
			$msg['parent-uri'] = $parent['parent-uri'];
			$msg['title'] = $parent['title'];
		} else {
			$msg['parent-uri'] = $item['thr-parent'];

			if (!empty($item['title'])) {
				$msg['title'] = $item['title'];
			} elseif (!empty($item['content-warning'])) {
				$msg['title'] = $item['content-warning'];
			} else {
				// Trying to generate a title out of the body
				$title = $item['body'];

				while (preg_match('#^(@\[url=([^\]]+)].*?\[\/url]\s)(.*)#is', $title, $matches)) {
					$title = $matches[3];
				}

				$title = trim(HTML::toPlaintext(BBCode::convert($title, false, 2, true), 0));

				if (strlen($title) > 20) {
					$title = substr($title, 0, 20) . '...';
				}

				$msg['title'] = $title;
			}
		}
		$msg['body'] = $item['body'];

		return Mail::insert($msg);
	}

	/**
	 * Fetches missing posts
	 *
	 * @param $url
	 * @param $child
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function fetchMissingActivity($url, $child = [])
	{
		if (!empty($child['receiver'])) {
			$uid = ActivityPub\Receiver::getFirstUserFromReceivers($child['receiver']);
		} else {
			$uid = 0;
		}

		$object = ActivityPub::fetchContent($url, $uid);
		if (empty($object)) {
			Logger::log('Activity ' . $url . ' was not fetchable, aborting.');
			return;
		}

		if (empty($object['id'])) {
			Logger::log('Activity ' . $url . ' has got not id, aborting. ' . json_encode($object));
			return;
		}

		if (!empty($child['author'])) {
			$actor = $child['author'];
		} elseif (!empty($object['actor'])) {
			$actor = $object['actor'];
		} elseif (!empty($object['attributedTo'])) {
			$actor = $object['attributedTo'];
		} else {
			// Shouldn't happen
			$actor = '';
		}

		if (!empty($object['published'])) {
			$published = $object['published'];
		} elseif (!empty($child['published'])) {
			$published = $child['published'];
		} else {
			$published = DateTimeFormat::utcNow();
		}

		$activity = [];
		$activity['@context'] = $object['@context'];
		unset($object['@context']);
		$activity['id'] = $object['id'];
		$activity['to'] = defaults($object, 'to', []);
		$activity['cc'] = defaults($object, 'cc', []);
		$activity['actor'] = $actor;
		$activity['object'] = $object;
		$activity['published'] = $published;
		$activity['type'] = 'Create';

		$ldactivity = JsonLD::compact($activity);

		$ldactivity['thread-completion'] = true;

		ActivityPub\Receiver::processActivity($ldactivity);
		Logger::log('Activity ' . $url . ' had been fetched and processed.');
	}

	/**
	 * perform a "follow" request
	 *
	 * @param array $activity
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function followUser($activity)
	{
		$uid = User::getIdForURL($activity['object_id']);
		if (empty($uid)) {
			return;
		}

		$owner = User::getOwnerDataById($uid);

		$cid = Contact::getIdForURL($activity['actor'], $uid);
		if (!empty($cid)) {
			self::switchContact($cid);
			DBA::update('contact', ['hub-verify' => $activity['id'], 'protocol' => Protocol::ACTIVITYPUB], ['id' => $cid]);
			$contact = DBA::selectFirst('contact', [], ['id' => $cid, 'network' => Protocol::NATIVE_SUPPORT]);
		} else {
			$contact = [];
		}

		$item = ['author-id' => Contact::getIdForURL($activity['actor']),
			'author-link' => $activity['actor']];

		$note = Strings::escapeTags(trim(defaults($activity, 'content', '')));

		// Ensure that the contact has got the right network type
		self::switchContact($item['author-id']);

		$result = Contact::addRelationship($owner, $contact, $item, false, $note);
		if ($result === true) {
			ActivityPub\Transmitter::sendContactAccept($item['author-link'], $item['author-id'], $owner['uid']);
		}

		$cid = Contact::getIdForURL($activity['actor'], $uid);
		if (empty($cid)) {
			return;
		}

		if (empty($contact)) {
			DBA::update('contact', ['hub-verify' => $activity['id'], 'protocol' => Protocol::ACTIVITYPUB], ['id' => $cid]);
		}

		Logger::log('Follow user ' . $uid . ' from contact ' . $cid . ' with id ' . $activity['id']);
	}

	/**
	 * Update the given profile
	 *
	 * @param array $activity
	 * @throws \Exception
	 */
	public static function updatePerson($activity)
	{
		if (empty($activity['object_id'])) {
			return;
		}

		Logger::log('Updating profile for ' . $activity['object_id'], Logger::DEBUG);
		Contact::updateFromProbeByURL($activity['object_id'], true);
	}

	/**
	 * Delete the given profile
	 *
	 * @param array $activity
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function deletePerson($activity)
	{
		if (empty($activity['object_id']) || empty($activity['actor'])) {
			Logger::log('Empty object id or actor.', Logger::DEBUG);
			return;
		}

		if ($activity['object_id'] != $activity['actor']) {
			Logger::log('Object id does not match actor.', Logger::DEBUG);
			return;
		}

		$contacts = DBA::select('contact', ['id'], ['nurl' => Strings::normaliseLink($activity['object_id'])]);
		while ($contact = DBA::fetch($contacts)) {
			Contact::remove($contact['id']);
		}
		DBA::close($contacts);

		Logger::log('Deleted contact ' . $activity['object_id'], Logger::DEBUG);
	}

	/**
	 * Accept a follow request
	 *
	 * @param array $activity
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function acceptFollowUser($activity)
	{
		$uid = User::getIdForURL($activity['object_actor']);
		if (empty($uid)) {
			return;
		}

		$cid = Contact::getIdForURL($activity['actor'], $uid);
		if (empty($cid)) {
			Logger::log('No contact found for ' . $activity['actor'], Logger::DEBUG);
			return;
		}

		self::switchContact($cid);

		$fields = ['pending' => false];

		$contact = DBA::selectFirst('contact', ['rel'], ['id' => $cid]);
		if ($contact['rel'] == Contact::FOLLOWER) {
			$fields['rel'] = Contact::FRIEND;
		}

		$condition = ['id' => $cid];
		DBA::update('contact', $fields, $condition);
		Logger::log('Accept contact request from contact ' . $cid . ' for user ' . $uid, Logger::DEBUG);
	}

	/**
	 * Reject a follow request
	 *
	 * @param array $activity
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function rejectFollowUser($activity)
	{
		$uid = User::getIdForURL($activity['object_actor']);
		if (empty($uid)) {
			return;
		}

		$cid = Contact::getIdForURL($activity['actor'], $uid);
		if (empty($cid)) {
			Logger::log('No contact found for ' . $activity['actor'], Logger::DEBUG);
			return;
		}

		self::switchContact($cid);

		if (DBA::exists('contact', ['id' => $cid, 'rel' => Contact::SHARING])) {
			Contact::remove($cid);
			Logger::log('Rejected contact request from contact ' . $cid . ' for user ' . $uid . ' - contact had been removed.', Logger::DEBUG);
		} else {
			Logger::log('Rejected contact request from contact ' . $cid . ' for user ' . $uid . '.', Logger::DEBUG);
		}
	}

	/**
	 * Undo activity like "like" or "dislike"
	 *
	 * @param array $activity
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function undoActivity($activity)
	{
		if (empty($activity['object_id'])) {
			return;
		}

		if (empty($activity['object_actor'])) {
			return;
		}

		$author_id = Contact::getIdForURL($activity['object_actor']);
		if (empty($author_id)) {
			return;
		}

		Item::delete(['uri' => $activity['object_id'], 'author-id' => $author_id, 'gravity' => GRAVITY_ACTIVITY]);
	}

	/**
	 * Activity to remove a follower
	 *
	 * @param array $activity
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function undoFollowUser($activity)
	{
		$uid = User::getIdForURL($activity['object_object']);
		if (empty($uid)) {
			return;
		}

		$owner = User::getOwnerDataById($uid);

		$cid = Contact::getIdForURL($activity['actor'], $uid);
		if (empty($cid)) {
			Logger::log('No contact found for ' . $activity['actor'], Logger::DEBUG);
			return;
		}

		self::switchContact($cid);

		$contact = DBA::selectFirst('contact', [], ['id' => $cid]);
		if (!DBA::isResult($contact)) {
			return;
		}

		Contact::removeFollower($owner, $contact);
		Logger::log('Undo following request from contact ' . $cid . ' for user ' . $uid, Logger::DEBUG);
	}

	/**
	 * Switches a contact to AP if needed
	 *
	 * @param integer $cid Contact ID
	 * @throws \Exception
	 */
	private static function switchContact($cid)
	{
		$contact = DBA::selectFirst('contact', ['network'], ['id' => $cid, 'network' => Protocol::NATIVE_SUPPORT]);
		if (!DBA::isResult($contact) || in_array($contact['network'], [Protocol::ACTIVITYPUB, Protocol::DFRN])) {
			return;
		}

		Logger::log('Change existing contact ' . $cid . ' from ' . $contact['network'] . ' to ActivityPub.');
		Contact::updateFromProbe($cid, Protocol::ACTIVITYPUB);
	}

	/**
	 * Collects implicit mentions like:
	 * - the author of the parent item
	 * - all the mentioned conversants in the parent item
	 *
	 * @param array $parent Item array with at least ['id', 'author-link', 'alias']
	 * @return array
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	private static function getImplicitMentionList(array $parent)
	{
		if (Config::get('system', 'disable_implicit_mentions')) {
			return [];
		}

		$parent_terms = Term::tagArrayFromItemId($parent['id'], [Term::MENTION, Term::IMPLICIT_MENTION]);

		$parent_author = Contact::getDetailsByURL($parent['author-link'], 0);

		$implicit_mentions = [];
		if (empty($parent_author)) {
			Logger::notice('Author public contact unknown.', ['author-link' => $parent['author-link'], 'item-id' => $parent['id']]);
		} else {
			$implicit_mentions[] = $parent_author['url'];
			$implicit_mentions[] = $parent_author['nurl'];
			$implicit_mentions[] = $parent_author['alias'];
		}

		if (!empty($parent['alias'])) {
			$implicit_mentions[] = $parent['alias'];
		}

		foreach ($parent_terms as $term) {
			$contact = Contact::getDetailsByURL($term['url'], 0);
			if (!empty($contact)) {
				$implicit_mentions[] = $contact['url'];
				$implicit_mentions[] = $contact['nurl'];
				$implicit_mentions[] = $contact['alias'];
			}
		}

		return $implicit_mentions;
	}

	/**
	 * Strips from the body prepended implicit mentions
	 *
	 * @param string $body
	 * @param array $potential_mentions
	 * @return string
	 */
	private static function removeImplicitMentionsFromBody($body, array $potential_mentions)
	{
		if (Config::get('system', 'disable_implicit_mentions')) {
			return $body;
		}

		$kept_mentions = [];

		// Extract one prepended mention at a time from the body
		while(preg_match('#^(@\[url=([^\]]+)].*?\[\/url]\s)(.*)#is', $body, $matches)) {
			if (!in_array($matches[2], $potential_mentions) ) {
				$kept_mentions[] = $matches[1];
			}

			$body = $matches[3];
		}

		// Re-appending the kept mentions to the body after extraction
		$kept_mentions[] = $body;

		return implode('', $kept_mentions);
	}

	private static function convertImplicitMentionsInTags($activity_tags, array $potential_mentions)
	{
		if (Config::get('system', 'disable_implicit_mentions')) {
			return $activity_tags;
		}

		foreach ($activity_tags as $index => $tag) {
			if (in_array($tag['href'], $potential_mentions)) {
				$activity_tags[$index]['name'] = preg_replace(
					'/' . preg_quote(Term::TAG_CHARACTER[Term::MENTION], '/') . '/',
					Term::TAG_CHARACTER[Term::IMPLICIT_MENTION],
					$activity_tags[$index]['name'],
					1
				);
			}
		}

		return $activity_tags;
	}
}
