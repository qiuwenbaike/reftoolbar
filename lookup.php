<?php
/*
	Copyright 2013 Alex Zaddach. (mrzmanwiki@gmail.com)
	Copyright 2021 WaitSpring (me@waitspring.com) and other Qiuwen Baike contributors

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.

*/

/*
	This is an API for retrieving bibliographic data using a PMID, ISBN, DOI, or URL.
	Example usage: lookup.php?isbn=9781848449510&template=book
*/

header('Content-type: text/javascript');

class PMIDLookup {

	private $id;

	public function __construct( $id ) {
		$this->id = $id;
	}

	private function normalizeMonth( $monthString ) {
		$months = ['Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04',
			'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08',
			'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12'];
		// If the date covers multiple months (e.g. Jul-Aug), just take the first one
		$monthPieces = explode('-', $monthString);
		$month = $monthPieces[0];
		if ( array_key_exists( $month, $months) ) {
			return $months[$month];
		} else {
			return false;
		}
	}

	public function getResult() {
		$url = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?';
		$url .= "&db=pubmed";
		$url .= '&tool=Qiuwen-1.1-refToolBar';
		$url .= '&email=siteoperator@qiuwen.org';
		$url .= "&id={$this->id}";
		$url .= '&retmode=xml';

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Qiuwen/1.1 (refToolBar)');
		$xml = curl_exec($ch);
		curl_close($ch);
		libxml_use_internal_errors(true); // Suppress errors from invalid XML
		$data = simplexml_load_string($xml);
		$result = array();
		if ($data && property_exists($data, 'DocSum') && property_exists($data->DocSum, 'Item')) {
			foreach($data->DocSum->Item as $i) {
				switch ($i['Name']) {
					case 'PubDate':
						$result['fulldate'] = true;
						$date = explode (" ", (string)$i);
						if (!isset($date[2])) {
							$result['fulldate'] = false;
							$result['year'] = $date[0];
							if (isset($date[1])) {
								$result['month'] = $this->normalizeMonth($date[1]);
							} else {
								$result['month'] = false;
							}
						}
						$result['date'] = $date[0];
						if (isset($date[1])) {
							$month = $this->normalizeMonth($date[1]);
							if ($month) {
								$result['date'].='-'.$month;
								if (isset($date[2])) {
									$result['date'].='-'.str_pad($date[2], 2, "0", STR_PAD_LEFT);
								}
							}
						}
						break;
					case 'FullJournalName':
						$result['journal'] = (string)$i;
						break;
					case 'Title':
						$result['title'] = (string)$i;
						break;
					case 'Volume':
						$result['volume'] = (string)$i;
						break;
					case 'Issue':
						$result['issue'] = (string)$i;
						break;
					case 'Pages':
						$result['pages'] = (string)$i;
						break;
					case 'DOI':
						$result['doi'] = (string)$i;
						break;
					case 'AuthorList':
						foreach($i->Item as $a) {
							$r = preg_match('/^(.*?) (\S*)$/', (string)$a, $match);
							if ($r) {
								$result['authors'][] = array( $match[1], $match[2] );
							} else {
								$result['authors'][] = array( (string)$a, '' );
							}
						}
						break;
				}
			}
		}
		return $result;
	}
}

class CitoidLookup {

	private $id;

	public function __construct( $id ) {
		$this->id = $id;
	}

	public function getResult() {
		// Sanity check the ID (make sure it has been URL encoded)
		if ( strpos( $this->id, ':' ) !== false || strpos( $this->id, '/' ) !== false ) {
			$this->id = urlencode( $this->id );
		}
		// See https://www.mediawiki.org/wiki/Citoid
		$url = "https://citoid.qiuwen.net.cn/api?action=query&format=mediawiki&search=" . $this->id;
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Qiuwen/1.1 (refToolBar)');
		$json = curl_exec($ch);
		curl_close($ch);
		$data = json_decode($json, true);
		$result = array();
		if ( $data && isset( $data[0] ) ) {
			$itemType = $data[0]['itemType'];
			foreach ( $data[0] as $key => $value ) {
				// Replace any pipe characters since these will break the templates
				if ( is_string( $value ) ) {
					$value = str_replace( '|', '{{!}}', $value );
				}
				switch ( $key ) {
					case 'publicationTitle':
						if ( $itemType === 'bookSection' && $_GET['template'] === 'book' ) {
							$result['title'] = $value;
						} else {
							$result['journal'] = $value;
						}
						break;
					case 'title':
						if ( $itemType === 'bookSection' && $_GET['template'] === 'book' ) {
							$result['chapter'] = $value;
						} else {
							$result['title'] = $value;
						}
						break;
					case 'websiteTitle':
						// RefToolbar maps all work titles to journal
						$result['journal'] = $value;
						break;
					case 'volume':
						$result['volume'] = $value;
						break;
					case 'issue':
						$result['issue'] = $value;
						break;
					case 'edition':
						// Citoid API returns 'X edition', but templates expect just 'X'
						$value = str_replace(' edition','',$value);
						$value = str_replace(' ed.','',$value);
						$value = str_replace(' ed','',$value);
						$result['edition'] = $value;
						break;
					case 'publisher':
						$result['publisher'] = $value;
						break;
					case 'pages':
						$result['pages'] = $value;
						break;
					case 'ISBN':
						$result['isbn'] = $value[0];
						break;
					case 'ISSN':
						$result['issn'] = $value[0];
						break;
					case 'date':
						$result['date'] = $value;
						break;
					case 'DOI':
						$result['doi'] = $value;
						break;
					case 'language':
						$result['language'] = $value;
						break;
					case 'place':
						$result['location'] = $value;
						break;
					case 'author':
						foreach( $value as $author ) {
							// Prevent undefined errors
							$firstName = null;
							$lastName = null;
							// Make sure first name doesn't start with a number
							if ( preg_match( '/^\d/', $author[0] ) !== 1 ) {
								$firstName = $author[0];
							}
							// Make sure last name doesn't start with a number
							if ( preg_match( '/^\d/', $author[1] ) !== 1 ) {
								$lastName = $author[1];
							}
							if ( $firstName && $lastName ) {
								// RefToolbar gadget expects lastName, firstName
								$result['authors'][] = array($lastName, $firstName);
							}
						}
				}
			}
		}
		return $result;
	}

}

$k = array_keys($_GET);
if (!isset($k[0])) {
	die(1);
}
switch($k[0]) {
	case 'pmid':
		$class = 'PMIDLookup';
		break;
	case 'isbn':
		$class = 'CitoidLookup';
		break;
	case 'doi':
		$class = 'CitoidLookup';
		break;
	case 'url':
		$class = 'CitoidLookup';
		break;
	default:
		die(1);
}
$idval = trim($_GET[$k[0]]);
$look = new $class($idval);
$res = $look->getResult();
if (isset($_GET['template'])) {
	$template = $_GET['template'];
	echo 'CiteTB.autoFill('.json_encode($res).", '$template', '{$k[0]}')";
}
