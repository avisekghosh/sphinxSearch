<?php

class Search {

	private $whereArr = array();
	private $whereFacet = array();
	private $whereStr = "";
	private $selectArr = array();
	private $selectStr = "";
	private $index = "";
	private $limit = "";
	private $data = array();
	private $sConn;
	private $rangeStr = "";
	private $rangeStrArr=array();
	private $dConn;
	private $defaultFacetData = array();
	private $whereFacetStr = "";
	private $sql;
	private $orderBy;
	private $isExeceptionFound=false;
	private $maxMatch="";
	private $maxFacetLimit="";

	public function __construct() {
		$this -> loadDependency();
	}

	public function process($query, $filter = null, $page = null, $itemsPerPage = null, $orderBy=null) {
		if($this->isExeceptionFound){ return false; exit(); }
		$page = (isset($page)) ? $page : "1";
		$itemsPerPage = (isset($itemsPerPage)) ? $itemsPerPage : "10";
		$this -> init();
		$this->orderBy=(isset($orderBy)) ? "ORDER BY ".$orderBy : "";
		$this -> whereMaker($filter);
		$this -> limitMaker($page, $itemsPerPage);
		$this -> sqlMaker($query);
		$this -> executeSql($query);
		return $this->data;
	}

	private function init() {
		$this->whereArr = array();
		$this->whereFacet = array();
		$this->whereStr = "";
		$this->selectArr = array();
		$this->selectStr = "";
		$this->limit = "";
		$this->data = array();
		$this->rangeStr = "";
		$this->rangeStrArr=array();
		$this->defaultFacetData = array();
		$this->whereFacetStr = "";
		$this->sql="";
		$this->orderBy="";
	}

	private function whereMaker($filterArr) {
		$whereArr = array();
		foreach ($filterArr as $fk => $fv) {

			$tempArr = explode("-", $fv["filter"]);
			$isFilter = ($tempArr[0] == "") ? false : true;
			if ($fv["isRange"]!=false) {
				$stmt = $this -> sConn -> prepare("SELECT MAX(".$fk.") as mp from " . $this -> index);
				$stmt -> execute();
				$rangeData = $stmt -> fetchAll();
				$rangeLimit = $rangeData[0]["mp"] / $fv["isRange"];
				$rangeArr = array();
				for ($i = 0; $i < $rangeLimit+1; $i++) {
					$rangeArr[] = $i * $fv["isRange"];
				}

				unset($rangeArr[0]);
				$rangeStr = implode(",", $rangeArr);
				// var_dump($rangeStr); die;
				$this -> rangeStr = "INTERVAL($fk," . $rangeStr . ") as ".$fk."_seg";
				$this -> rangeStrArr[$fk]=	$this -> rangeStr;
				$tempPrice=array();
				foreach ($tempArr as $rData) {
					$tempPrice[] = ' (' . $fk . ' >= ' . ($rData * $fv["isRange"]) . ' AND ' . $fk . ' <= ' . (($rData + 1) * $fv["isRange"]) . ') ';
				}
				if ($isFilter) {
					$tempPrice = implode(' OR ', $tempPrice);
					$this -> selectArr[$fk] = 'IF(' . $tempPrice . ',1,0) as w_'.$fk;
					$whereArr[$fk] = 'w_'.$fk.' = 1';
				} else {
					$whereArr[$fk] = '';
				}
			} else {
				$tempString = implode(", ", $tempArr);
				$whereArr[$fk] = ($isFilter) ? " $fk in (" . $tempString . ") " : "";
			}

		}
		$this -> selectStr = implode(", ", $this -> selectArr);
		$whereTemp = $whereArr;
		$whereFacet = array();
		if (count($whereArr) > 0) {
			foreach ($whereArr as $wk => $wv) {

				if (isset($whereTemp[$wk])) {
					$order=(isset($filterArr[$wk]["order"])) ? $filterArr[$wk]["order"] : "ASC";
					$key=(isset($this->rangeStrArr[$wk])) ? $this->rangeStrArr[$wk] : $wk;
					// $whereFacet[$wk] = "FACET " . $key . " ORDER BY FACET() ".$order.$this->maxFacetLimit;
					$whereFacet[$wk] = "FACET " . $key . " ORDER BY count(*) ".$order.$this->maxFacetLimit;
				}

			}
		}

		$this -> whereArr = $whereArr;
		// var_dump($this -> whereArr); die;
		$countWhereArr = count($whereArr);
		$absWhereArr = array();
		foreach ($whereArr as $wk => $wv) {
			if ($wv != "") {
				$absWhereArr[$wk] = $wv;
			}
		}
		$this -> whereStr = implode(' AND ', $absWhereArr);
		$this -> whereStr = ($this -> whereStr != "") ? " AND " . $this -> whereStr : "";

		$this -> whereFacet = $whereFacet;
		$this -> whereFacetStr = implode(" ", $whereFacet);
	}

	private function limitMaker($page, $itemsPerPage) {
		$start = ($page - 1) * $itemsPerPage;
		$this -> limit = "LIMIT $start,$itemsPerPage";
		$this->limit.=$this->maxMatch;
	}

	private function sqlMaker($query = null) {
		$q = (isset($query)) ? $query : "";
		$mainSelectStr = (count($this -> selectArr) > 0) ? $this -> selectStr . "," : "";
		$sql = "SELECT $mainSelectStr * FROM $this->index WHERE MATCH('".$q."') $this->whereStr $this->orderBy $this->limit $this->whereFacetStr;";
		// var_dump($sql); die;
		// $sql = "SELECT $mainSelectStr * FROM $this->index WHERE MATCH('') $this->whereStr $this->orderBy $this->limit $this->whereFacetStr;";

		// $sql = "SELECT $mainSelectStr * FROM $this->index WHERE MATCH('') $this->whereStr $this->orderBy $this->limit $this->whereFacetStr;";
		// echo $sql; die;


		$this -> sql = $sql;
		// echo $sql; die;
	}

	private function executeSql($query = "") {
		$q = (isset($query)) ? $query : "";
		$data = array();
		$stmt = $this -> sConn -> prepare($this -> sql);
		// $stmt -> bindValue(':match', $q, PDO::PARAM_STR);
		$stmt -> execute();
		$this->formatData($stmt);
	}

	private function formatData($stmt) {
		$facetArr=array_merge(array("main"=>""),$this->whereFacet);
		$sqlKeys=array();
		$i=0;
		$data=array();
		foreach ($facetArr as $k => $v) {
			if($i==0)
				$data[$k]=$stmt -> fetchAll(PDO::FETCH_ASSOC);
			else
				$data["facet"][$k]=$stmt -> fetchAll(PDO::FETCH_ASSOC);
			$stmt -> nextRowset();
			$i++;
		}
		$meta = $this -> sConn->query("SHOW META")->fetchAll();
		foreach ($meta as $m) {
				$meta_map[$m['Variable_name']] = $m['Value'];
		}
		$data["meta"]=$meta_map;
		$this -> data = $data;
	}

	private function loadDependency() {
		$realPath = realpath('.') . "/";
		require_once ($realPath . "config/config.php");
		$this -> setConnection();
	}

	private function setDefaultFacet($data) {
		$this -> defaultFacetData = $data;
	}

	private function setConnection() {
		try {
			$this -> sConn = new PDO(SPHINX_CON);
			$this -> dConn = new PDO(DB_DSN, DB_USERNAME, DB_PASSWORD);
			$this -> index = SPHINX_INDEX;
		} catch (PDOException $e) {
			$this->isExeceptionFound=true;
		}
	}

	public function setIndex($indexName){
		$this -> index = $indexName;
	}

	public function setMaxMatches($matches){
		$this->maxMatch=" OPTION max_matches = ".$matches;
	}

	public function setMaxFacetLimit($max){
		$this->maxFacetLimit=" Limit ".$max;
	}
}
 ?>
