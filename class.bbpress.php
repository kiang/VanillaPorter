<?php
/**
 * bbPress exporter tool
 *
 * @copyright Vanilla Forums Inc. 2010
 * @license http://opensource.org/licenses/gpl-2.0.php GNU GPL2
 * @package VanillaPorter
 */
 
$Supported['bbPress'] = array('name'=>'bbPress 1.*', 'prefix' => 'bb_');

class BbPress extends ExportController {
   /** @var array Required tables => columns */
   protected $SourceTables = array(
      'forums' => array(),
      'posts' => array(),
      'topics' => array(),
      'users' => array('ID', 'user_login', 'user_pass', 'user_email', 'user_registered'),
      'meta' => array()
   );
   
   /**
    * Forum-specific export format.
    * @param ExportModel $Ex
    */
   protected function ForumExport($Ex) {
      // Begin
      $Ex->BeginExport('', 'bbPress 1.*', array('HashMethod' => 'Vanilla'));

      // Users
      $User_Map = array(
         'ID'=>'UserID',
         'user_login'=>'Name',
         'user_pass'=>'Password',
         'user_email'=>'Email',
         'user_registered'=>'DateInserted'
      );
      $Ex->ExportTable('User', "select * from :_users", $User_Map);  // ":_" will be replace by database prefix

      // Roles
      $Ex->ExportTable('Role', 
         "select 1 as RoleID, 'Guest' as Name
         union select 2, 'Key Master'
         union select 3, 'Administrator'
         union select 4, 'Moderator'
         union select 5, 'Member'
         union select 6, 'Inactive'
         union select 7, 'Blocked'");

      // UserRoles
      $UserRole_Map = array(
         'user_id'=>'UserID'
      );
      $Ex->ExportTable('UserRole', 
         "select distinct
           user_id,
           case when locate('keymaster', meta_value) <> 0 then 2
           when locate('administrator', meta_value) <> 0 then 3
           when locate('moderator', meta_value) <> 0 then 4
           when locate('member', meta_value) <> 0 then 5
           when locate('inactive', meta_value) <> 0 then 6
           when locate('blocked', meta_value) <> 0 then 7
           else 1 end as RoleID
         from :_usermeta
         where meta_key = 'bb_capabilities'", $UserRole_Map);

      // Categories
      $Category_Map = array(
         'forum_id'=>'CategoryID',
         'forum_name'=>'Name',
         'forum_desc'=>'Description',
         'form_slug'=>'UrlCode',
         'left_order'=>'Sort'
      );
      $Ex->ExportTable('Category', "select *,
         nullif(forum_parent,0) as ParentCategoryID
         from :_forums", $Category_Map);

      // Discussions
      $Discussion_Map = array(
         'topic_id'=>'DiscussionID',
         'forum_id'=>'CategoryID',
         'topic_poster'=>'InsertUserID',
         'topic_title'=>'Name',
			'Format'=>'Format',
         'topic_start_time'=>'DateInserted',
         'topic_sticky'=>'Announce'
      );
      $Ex->ExportTable('Discussion', "select t.*,
				'Html' as Format,
            case t.topic_open when 0 then 1 else 0 end as Closed
         from :_topics t", $Discussion_Map);

      // Comments
      $Comment_Map = array(
         'post_id' => 'CommentID',
         'topic_id' => 'DiscussionID',
         'post_text' => 'Body',
			'Format' => 'Format',
         'Body' => array('Column'=>'Body','Filter'=>'bbPressTrim'),
         'poster_id' => 'InsertUserID',
         'post_time' => 'DateInserted'
      );
      $Ex->ExportTable('Comment', "select p.*,
				'Html' as Format
         from :_posts p", $Comment_Map);

      // Conversations.

      // The export is different depending on the table layout.
      $PM = $Ex->Exists('bbpm', array('ID', 'pm_title', 'pm_from', 'pm_to', 'pm_text', 'sent_on', 'pm_thread'));
      $ConversationVersion = '';

      if ($PM === TRUE) {
         // This is from an old version of the plugin.
         $ConversationVersion = 'old';
      } elseif (is_array($PM) && count(array_intersect(array('ID', 'pm_from', 'pm_text', 'sent_on', 'pm_thread'), $PM)) == 0) {
         // This is from a newer version of the plugin.
         $ConversationVersion = 'new';
      }

      if ($ConversationVersion) {
         // Conversation.
         $Conv_Map = array(
            'pm_thread' => 'ConversationID',
            'pm_from' => 'InsertUserID'
         );
         $Ex->ExportTable('Conversation',
            "select *, from_unixtime(sent_on) as DateInserted
            from :_bbpm
            where thread_depth = 0", $Conv_Map);

         // ConversationMessage.
         $ConvMessage_Map = array(
            'ID' => 'MessageID',
            'pm_thread' => 'ConversationID',
            'pm_from' => 'InsertUserID',
            'pm_text' => array('Column'=>'Body','Filter'=>'bbPressTrim')
         );
         $Ex->ExportTable('ConversationMessage',
            'select *, from_unixtime(sent_on) as DateInserted
            from :_bbpm', $ConvMessage_Map);

         // UserConversation.
         $Ex->Query("create temporary table bbpmto (UserID int, ConversationID int)");

         if ($ConversationVersion == 'new') {
            $To = $Ex->Query("select object_id, meta_value from bb_meta where object_type = 'bbpm_thread' and meta_key = 'to'", TRUE);
            if (is_resource($To)) {
               while (($Row = @mysql_fetch_assoc($To)) !== false) {
                  $Thread = $Row['object_id'];
                  $Tos = explode(',', trim($Row['meta_value'], ','));
                  $ToIns = '';
                  foreach ($Tos as $ToID) {
                     $ToIns .= "($ToID,$Thread),";
                  }
                  $ToIns = trim($ToIns, ',');

                  $Ex->Query("insert bbpmto (UserID, ConversationID) values $ToIns", TRUE);
               }
               mysql_free_result($To);

               $Ex->ExportTable('UserConversation', 'select * from bbpmto');
            }
         } else {
            $ConUser_Map = array(
                'pm_thread' => 'ConversationID',
                'pm_from' => 'UserID'
            );
            $Ex->ExportTable('UserConversation',
               'select distinct
                 pm_thread,
                 pm_from,
                 del_sender as Deleted
               from bb_bbpm

               union

               select distinct
                 pm_thread,
                 pm_to,
                 del_reciever
               from bb_bbpm', $ConUser_Map);
         }
      }

      // End
      $Ex->EndExport();
   }
}

function bbPressTrim($Text) {
   return rtrim(bb_code_trick_reverse($Text));
}

function bb_code_trick_reverse( $text ) {
   $text = preg_replace_callback("!(<pre><code>|<code>)(.*?)(</code></pre>|</code>)!s", 'bb_decodeit', $text);
   $text = str_replace(array('<p>', '<br />'), '', $text);
   $text = str_replace('</p>', "\n", $text);
   $text = str_replace('<coded_br />', '<br />', $text);
   $text = str_replace('<coded_p>', '<p>', $text);
   $text = str_replace('</coded_p>', '</p>', $text);
   return $text;
}

function bb_decodeit( $matches ) {
	$text = $matches[2];
	$trans_table = array_flip(get_html_translation_table(HTML_ENTITIES));
	$text = strtr($text, $trans_table);
	$text = str_replace('<br />', '<coded_br />', $text);
	$text = str_replace('<p>', '<coded_p>', $text);
	$text = str_replace('</p>', '</coded_p>', $text);
	$text = str_replace(array('&#38;','&amp;'), '&', $text);
	$text = str_replace('&#39;', "'", $text);
	if ( '<pre><code>' == $matches[1] )
		$text = "\n$text\n";
	return "`$text`";
}
?>