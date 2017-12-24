<?php
require_once( 'header.php' );
error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); // E_ALL|
ini_set('display_errors', 'On');
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
<form action="index.php">
  <div class="ui fluid input">
  <input style="margin-bottom: 0.5em" type="text" name="lang" <?php
  if ( isset($_REQUEST['lang']) && $_REQUEST['lang'] ) {
	echo 'value="' . $_REQUEST['lang'];
  } else
	echo 'placeholder="Enter language ISO code e.g. fa';
?>"></div>
<div class="ui checkbox">
  <input type="checkbox" name="description" <?php
  if ( $_REQUEST['description'] !== 'off' ) {
      echo 'checked';
  }?>>
  <label>Changes in descriptions</label>
</div>
<div class="ui checkbox">
  <input type="checkbox" name="labels" <?php
  if ( isset($_REQUEST['labels'] ) ) {
      echo 'checked';
  }?>>
  <label>Changes in labels and aliases</label>
</div>
<div class="ui checkbox">
  <input type="checkbox" name="sitelinks" <?php
  if ( isset($_REQUEST['sitelinks'] ) ) {
      echo 'checked';
  }?>>
  <label>Sitelink removals</label>
</div>
</br >
  <button type="submit" class="ui primary button">Search</button>
</form>
<?php

function userlink( $username ) {
	$anonymousPattern = '/^\d+\.\d+\.\d+\.\d+|[0-9a-f]+(?::[0-9a-f]*)+$/i';
	if ( preg_match( $anonymousPattern, $username ) ) {
		$page = "Special:Contributions/{$username}";
	} else {
		$page = "User:{$username}";
	}
	return "https://www.wikidata.org/wiki/${page}";
}

if (isset( $_REQUEST['lang'] ) && $_REQUEST['lang'] && (
    $_REQUEST['description'] !== 'off' || isset( $_REQUEST['labels'] ) || isset($_REQUEST['sitelinks'] )
) ) {
	$lang = mysql_escape_string ( $_REQUEST['lang'] );
	if (isset($_REQUEST['limit']) && $_REQUEST['limit']) {
		$limit = (int)$_REQUEST['limit'];
	} else {
		$limit = 50;
	}
	$limit = addslashes( (string)min( [ $limit, 500 ] ) );
	$dbmycnf = parse_ini_file("../replica.my.cnf");
	$dbuser = $dbmycnf['user'];
	$dbpass = $dbmycnf['password'];
	unset($dbmycnf);
	$dbhost = "wikidatawiki.web.db.svc.eqiad.wmflabs";
	$dbname = "wikidatawiki_p";
	$db = new PDO('mysql:host='.$dbhost.';dbname='.$dbname.';charset=utf8', $dbuser, $dbpass);
	$conditions = [];
	if ( $_REQUEST['description'] !== 'off' ) {
		$conditions[] = "rc_comment REGEXP 'wbsetdescription\-(add|set|remove)...{$lang}'";
	}
	if ( isset($_REQUEST['labels'] ) ) {
		$conditions[] = "rc_comment REGEXP 'wbsetlabel\-(add|set|remove)...{$lang}'";
		$conditions[] = "rc_comment REGEXP 'wbsetaliases\-(add|set|remove|update)...{$lang}'";
	}
	if ( isset($_REQUEST['sitelinks'] ) ) {
		$conditions[] = "rc_comment REGEXP 'wbsetsitelink\-remove...{$lang}'";
	}
	$where = '(' . implode( ' OR ', $conditions ) . ')';
    $sql = "select rc_this_oldid, rc_title, rc_user_text, rc_comment from recentchanges where {$where} and rc_patrolled = 0 order by rc_id desc limit {$limit};";
	$result = $db->query($sql)->fetchAll();
	$entities = [];
	foreach ($result as $row) {
		$entities[] = $row['rc_title'];
	}
	$termSql = "select term_full_entity_id, term_text from wb_terms where term_language = '{$lang}' AND term_type = 'label' AND term_full_entity_id in ('" . implode("', '", $entities) . "')";
	$termResult = $db->query($termSql)->fetchAll();
	$termDictionary = [];
	foreach ($termResult as $row) {
		$termDictionary[$row['term_full_entity_id']] = $row['term_text'];
	}
	$revisionIds = [];
	foreach ($result as $row) {
		$revisionIds[] = $row['rc_this_oldid'];
	}
	// TODO: Cache
	$damagingId = $db->query("select oresm_id from ores_model where oresm_name = 'damaging' and oresm_is_current = 1")->fetchAll()[0]['oresm_id'];
	$oresSql = "select oresc_rev, oresc_probability from ores_classification where oresc_model = {$damagingId} AND oresc_rev in (" . implode(", ", $revisionIds) . ")";
	$oresResult = $db->query($oresSql)->fetchAll();
	$oresDictionary = [];
	foreach ($oresResult as $row) {
		$oresDictionary[$row['oresc_rev']] = $row['oresc_probability'];
	}
	echo '<table class="ui sortable celled table"><thead><tr><th>Edit ID</th><th>Entity ID</th><th>Username</th><th>Entity title</th><th>Edit summary</th><th>ORES damaging score</th></tr></thead><tbody>';
	foreach ($result as $row) {
		$id = $row['rc_this_oldid'];
		$username = $row['rc_user_text'];
		$title = $row['rc_title'];
		$summary = $row['rc_comment'];
		$label = $termDictionary[$row['rc_title']];
		$damagingScore = $oresDictionary[$row['rc_this_oldid']];
		$class = 'okay';
		if ( $damagingScore > 0.72 ) {
			$class = 'probably-damaging';
		}
		if ( $damagingScore > 0.983 ) {
			$class = 'very-likely-damaging';
		}
		$userlink = userlink( $username );
		echo "<tr class={$class}><td><a href=https://www.wikidata.org/wiki/Special:Diff/{$id} target='_blank'}>{$id}</a></td><td><a href=https://www.wikidata.org/wiki/{$title} target='_blank'}>{$title}</a></td><td><a href={$userlink} target='_blank'}>{$username}</a></td><td><a href=https://www.wikidata.org/wiki/{$title} target='_blank'}>{$label}</a></td><td>{$summary}</td><td>{$damagingScore}</td></tr>";
	}
} else {
    echo '<div class="ui negative message">
    <i class="close icon"></i>
    <div class="header">
     No query
    </div>
    <p>You need to enable at least one case</p></div>';
}