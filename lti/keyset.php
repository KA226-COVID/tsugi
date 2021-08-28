<?php

require_once "../config.php";
require_once "../util/helpers.php";

use Tsugi\Util\U;

// See the end of the file for some documentation references

$issuer = U::get($_GET,"issuer",false);
$issuer_id = U::get($_GET,"issuer_id",false);
$issuer_guid = U::get($_GET,"issuer_guid",false);

// Allow a format where the parameter is the primary key of the lti_key row
$key_id = null;
if ( is_numeric($issuer_guid) ) $key_id = intval($issuer_guid);

$rows = false;
if ( $key_id ) {
    $rows = $PDOX->allRowsDie(
        "SELECT lti13_pubkey FROM {$CFG->dbprefix}lti_issuer AS I
            JOIN {$CFG->dbprefix}lti_key AS K ON
                K.issuer_id = I.issuer_id
            WHERE key_id = :KID",
        array(":KID" => $key_id)
    );
} else if ( $issuer ) {
    $issuer_sha256 = hash('sha256', trim($issuer));
    $rows = $PDOX->allRowsDie(
        "SELECT lti13_pubkey FROM {$CFG->dbprefix}lti_issuer
            WHERE issuer_sha256 = :ISH AND lti13_pubkey IS NOT NULL",
        array(":ISH" => $issuer_sha256)
    );
} else if ( $issuer_id ) {
    $rows = $PDOX->allRowsDie(
        "SELECT lti13_pubkey FROM {$CFG->dbprefix}lti_issuer
            WHERE issuer_id = :IID AND lti13_pubkey IS NOT NULL",
        array(":IID" => $issuer_id)
    );
} else if ( strlen($issuer_guid) > 0 ) {
    $rows = $PDOX->allRowsDie(
        "SELECT lti13_pubkey FROM {$CFG->dbprefix}lti_issuer
            WHERE issuer_guid = :IGUID AND lti13_pubkey IS NOT NULL",
        array(":IGUID" => $issuer_guid)
    );
}

// Fall back if nothing was specified or found
if ( count($rows) < 1 )  {
    $rows = $PDOX->allRowsDie(
        "SELECT lti13_pubkey FROM {$CFG->dbprefix}lti_issuer
            WHERE lti13_pubkey IS NOT NULL"
    );
}

// Read up to the three most recent global keys
// TODO: Make this the only one needed :)
$stmt = $PDOX->queryReturnError(
    "SELECT pubkey as lti13_pubkey FROM {$CFG->dbprefix}lti_keyset
        WHERE deleted = 0 AND pubkey IS NOT NULL AND privkey IS NOT NULL
        ORDER BY created_at DESC LIMIT 3"
);
if ( $stmt->success ) {
    $global_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ( count($rows) < 1 ) $rows = $global_rows;
    else if ( count($global_rows) > 0 ) $rows = array_merge($rows, $global_rows);
}

if ( count($rows) < 1 ) die("Could not load key");

// $pubkey = "-----BEGIN PUBLIC KEY-----
// MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAvESXFmlzHz+nhZXTkjo2 9SBpamCzkd7SnpMXgdFEWjLfDeOu0D3JivEEUQ4U67xUBMY9voiJsG2oydMXjgkm GliUIVg+rhyKdBUJu5v6F659FwCj60A8J8qcstIkZfBn3yyOPVwp1FHEUSNvtbDL SRIHFPv+kh8gYyvqz130hE37qAVcaNME7lkbDmH1vbxi3D3A8AxKtiHs8oS41ui2 MuSAN9MDb7NjAlFkf2iXlSVxAW5xSek4nHGr4BJKe/13vhLOvRUCTN8h8z+SLORW abxoNIkzuAab0NtfO/Qh0rgoWFC9T69jJPAPsXMDCn5oQ3xh/vhG0vltSSIzHsZ8 pwIDAQAB
// -----END PUBLIC KEY-----";

// https://8gwifi.org/jwkconvertfunctions.jsp
// https://github.com/nov/jose-php/blob/master/src/JOSE/JWK.php
// https://github.com/nov/jose-php/blob/master/test/JOSE/JWK_Test.php

$jwks = array();
foreach ( $rows as $row ) {
    $pubkey = $row['lti13_pubkey'];
    $components = Helpers::build_jwk($pubkey);
    $jwks[] = $components;
}

// echo(json_encode($jwk));
// echo("\n");

header("Content-type: application/json");
// header("Content-type: text/plain");
$json = json_decode(<<<JSON
{
  "keys": [ ]
}
JSON
);

if ( ! $json ) {
    die('Unable to parse JSON '.json_last_error_msg());
}

$json->keys = $jwks;

echo(json_encode($json, JSON_PRETTY_PRINT));
