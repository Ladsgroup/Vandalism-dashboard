<?php
require_once( 'header.php' );
error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); // E_ALL|
ini_set('display_errors', 'On');

$lang = isset( $_REQUEST['lang'] ) ? $_REQUEST['lang'] : '';
preg_match_all( '/[a-z-]+/', strtolower( $lang ), $matches );
$languages = $matches[0];
if (isset($_REQUEST['limit']) && $_REQUEST['limit']) {
	$limit = (int)$_REQUEST['limit'];
} else {
	$limit = 50;
}
$hasFormData = $languages !== [] && (
    isset( $_REQUEST['description'] ) ||
    isset( $_REQUEST['labels'] ) ||
    isset( $_REQUEST['sitelinks'] )
);
?>
<style>
tr.probably-damaging {background-color: #fef7e6;}
tr.very-likely-damaging {background-color: #fee7e6;}
a {color: #36c;}
div.checkbox {margin: 1em;}
</style>
<script>
$(function() {
	$('table.sortable').tablesort();
});
</script>
<div style="padding: 3em;">
<form action="<?php echo basename( __FILE__ ); ?>">
  <label for="lang">Language code(s)</label><br>
  <div class="ui corner labeled input">
  <input style="margin-bottom: 0.5em" type="text" name="lang" id="lang" required placeholder="en,fa,nl-informal" <?php
  if ( $lang !== '' ) {
	echo 'value="' . htmlspecialchars( $lang ) . '"';
  }
?>>
  <div class="ui corner label">
    <i class="asterisk icon"></i>
  </div></div><br>
<label for="limit">Limit</label><br>
<div class="ui labeled input">
  <input style="margin-bottom: 0.5em" id="limit" name="limit" type="number" min="1" max="500" required value="<?php echo htmlspecialchars( $limit ); ?>">
</div>
<br>
<?php
function checkbox( $name, $description, $checked ) {
	$checkedAttribute = $checked ? 'checked' : '';
	echo <<< EOF
<div class="ui checkbox">
  <input type="checkbox" name="$name" id="$name" $checkedAttribute>
  <label for="$name">$description</label>
</div>
EOF;
}

checkbox( 'description', 'Changes in descriptions', isset( $_REQUEST['description'] ) || !$hasFormData );
checkbox( 'labels', 'Changes in labels and aliases', isset( $_REQUEST['labels'] ) );
checkbox( 'sitelinks', 'Sitelink removals', isset( $_REQUEST['sitelinks'] ) );
?>
<br>
  <button type="submit" class="ui primary button">Search</button>
</form>
<?php

function userlink( $username ) {
	$anonymousPattern = '/^\d+\.\d+\.\d+\.\d+|[0-9a-f]+(?::[0-9a-f]*)+$/i';
	if ( preg_match( $anonymousPattern, $username ) ) {
		$page = "Special:Contributions/{$username}";
	} else {
		$username = strtr( $username, ' ', '_' );
		$page = "User:{$username}";
	}
	return "https://www.wikidata.org/wiki/${page}";
}

function languagesCommentRegexp( $regexpPrefix, $languages ) {
	return "rc_comment REGEXP '" . $regexpPrefix . '...' . '(' . implode( '|', $languages ) . ")'";
}

if ( $hasFormData ) {
	$limit = addslashes( (string)min( [ $limit, 500 ] ) );
	$dbmycnf = parse_ini_file("../replica.my.cnf");
	$dbuser = $dbmycnf['user'];
	$dbpass = $dbmycnf['password'];
	unset($dbmycnf);
	$dbhost = "wikidatawiki.web.db.svc.eqiad.wmflabs";
	$dbname = "wikidatawiki_p";
	$db = new PDO('mysql:host='.$dbhost.';dbname='.$dbname.';charset=utf8', $dbuser, $dbpass);
	$conditions = [];
	if ( isset( $_REQUEST['description'] ) ) {
		$conditions[] = languagesCommentRegexp( 'wbsetdescription-(add|set|remove)', $languages );
	}
	if ( isset($_REQUEST['labels'] ) ) {
		$conditions[] = languagesCommentRegexp( 'wbsetlabel-(add|set|remove)', $languages );
		$conditions[] = languagesCommentRegexp( 'wbsetaliases-(add|set|remove|update)', $languages );
	}
	if ( isset($_REQUEST['sitelinks'] ) ) {
		$conditions[] = languagesCommentRegexp( 'wbsetsitelink-remove', $languages );
	}
	$where = '(' . implode( ' OR ', $conditions ) . ')';
	$sql = "SELECT rc_this_oldid, rc_title, rc_user_text, rc_comment " .
		"FROM recentchanges " .
		"WHERE {$where} AND rc_patrolled = 0 " .
		"ORDER BY rc_id desc LIMIT {$limit};";
	$result = $db->query($sql)->fetchAll();
	$entities = [];
	foreach ($result as $row) {
		$entities[] = $row['rc_title'];
	}
	$termSql = "select term_full_entity_id, term_text from wb_terms where term_language = '{$languages[0]}' AND term_type = 'label' AND term_full_entity_id in ('" . implode("', '", $entities) . "')";
	$termResult = $db->query($termSql)->fetchAll();
	$termDictionary = [];
	foreach ($termResult as $row) {
		$termDictionary[$row['term_full_entity_id']] = $row['term_text'];
	}
	$revisionIds = [];
	foreach ($result as $row) {
		$revisionIds[] = $row['rc_this_oldid'];
	}
	if ( $revisionIds !== [] ) {
		// TODO: Cache
		$damagingId = $db->query("select oresm_id from ores_model where oresm_name = 'damaging' and oresm_is_current = 1")->fetchAll()[0]['oresm_id'];
		$oresSql = "select oresc_rev, oresc_probability from ores_classification where oresc_model = {$damagingId} AND oresc_rev in (" . implode(", ", $revisionIds) . ")";
		$oresResult = $db->query($oresSql)->fetchAll();
		$oresDictionary = [];
		foreach ($oresResult as $row) {
			$oresDictionary[$row['oresc_rev']] = $row['oresc_probability'];
		}
	}
	echo '<table class="ui sortable celled table"><thead><tr><th>Edit ID</th><th>Entity ID</th><th>Username</th><th>Entity title</th><th>Edit summary</th><th>ORES damaging score</th></tr></thead><tbody>';
	echo "\n";
	foreach ($result as $row) {
		$id = $row['rc_this_oldid'];
		$username = $row['rc_user_text'];
		$title = $row['rc_title'];
		$summary = parseComment( $row['rc_comment'] );
		$label = htmlspecialchars( $termDictionary[$row['rc_title']] );
		$damagingScore = $oresDictionary[$row['rc_this_oldid']];
		$class = 'okay';
		if ( $damagingScore > 0.72 ) {
			$class = 'probably-damaging';
		}
		if ( $damagingScore > 0.983 ) {
			$class = 'very-likely-damaging';
		}
		$userlink = userlink( $username );
		echo "<tr class={$class}><td><a href=https://www.wikidata.org/wiki/Special:Diff/{$id} target='_blank'>{$id}</a></td><td><a href=https://www.wikidata.org/wiki/{$title} target='_blank'>{$title}</a></td><td><a href={$userlink} target='_blank'>{$username}</a></td><td><a href=https://www.wikidata.org/wiki/{$title} target='_blank'>{$label}</a></td><td>{$summary}</td><td>{$damagingScore}</td></tr>\n";
	}
	echo "</table>\n";
} else {
    echo '<div class="ui negative message">
    <i class="close icon"></i>
    <div class="header">
     No query
    </div>
    <p>You need to enable at least one case</p></div>';
}

function parseComment( $comment ) {
	$original = htmlspecialchars( $comment, ENT_QUOTES | ENT_HTML401, 'UTF-8' );

	$actions = [
		"add"    => "Added",
		"set"    => "Changed",
		"remove" => "Removed",
		"update" => "Changed",
	];

	$types = [
		"wbsetdescription" => ["description", "description"],
		"wbsetaliases"     => ["alias", "aliases"],
		"wbsetlabel"       => ["label", "labels"],
		"wbsetsitelink"    => ["sitelink", "sitelinks"],
	];

	if ( !preg_match( '%/\*\s*([a-z]+)-([a-z]+):(\d+)\|([\w-]+)\s*\*/(.+)?%', trim( $comment ), $match ) ) {
		return $original;
	}

	if ( isset( $actions[$match[2]] ) ) {
		$action = $actions[$match[2]];
	}
	$count = '';
	if ( isset( $types[$match[1]] ) ) {
		$type = $match[3] > 1 ? $types[$match[1]][1] : $types[$match[1]][0];
		$count = $match[3] > 1 ? " $match[3]" : '';
	}
	$comment = htmlspecialchars( trim( $match[5] ) );

	if ( !empty( $action ) && !empty( $type ) ) {
		return "<span title=\"$original\">$action{$count} $type in <i>{$match[4]}</i>: <b>$comment</b></span>";
	}

	return $original;
}

?>
</div>
</body>
</html>
