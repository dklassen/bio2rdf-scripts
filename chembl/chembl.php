<?php
	/*
		Need to develop procedure to download and load a mysql database.
		requires that you have 'mysql' installed and have root access

		source_url: ftp://ftp.ebi.ac.uk/pub/databases/chembl/ChEMBLdb/latest/chembl_14_mysql.tar.gz
	*/


/**
	Copyright (C) 2012 Dana Klassen

	Permission is hereby granted, free of charge, to any person obtaining a copy of
	this software and associated documentation files (the "Software"), to deal in
	the Software without restriction, including without limitation the rights to
	use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
	of the Software, and to permit persons to whom the Software is furnished to do
	so, subject to the following conditions:

	The above copyright notice and this permission notice shall be included in all
	copies or substantial portions of the Software.

	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
	SOFTWARE.
*/

/**
 * Chembl Parser
 * @version: 0.1
 * @author: Dana Klassen
 * descreiption:
*/
require('../../php-lib/rdfapi.php');

class ChemblParser extends RDFFactory {

	private $version = null ;

	function __construct($argv) {
		parent::__construct();
			$this->SetDefaultNamespace("chembl");
			
			// set and print application parameters
			$this->AddParameter('files',true,'all|compounds|targets|assays|references|properties','','files to process');
			$this->AddParameter('indir',false,null,'/data/download/gene/','directory to download into and parse from');
			$this->AddParameter('outdir',false,null,'/data/rdf/gene/','directory to place rdfized files');
			$this->AddParameter('graph_uri',false,null,null,'provide the graph uri to generate n-quads instead of n-triples');
			$this->AddParameter('gzip',false,'true|false','true','gzip the output');
			$this->AddParameter('download',false,'true|false','false','set true to download files');
			$this->AddParameter('user',false,false,'dba','set the user to access the mysql chembl database');
			$this->AddParameter('pass',false,false,'dba','set the password of the user to access the mysql chembl database');
			$this->AddParameter('db_name',false,null,'chembl_14','set the database table to configure/access the mysql database');
			$this->AddParameter('download_url',false,null,'ftp://ftp.ebi.ac.uk/pub/databases/chembl/ChEMBLdb/latest/');
			
			if($this->SetParameters($argv) == FALSE) {
				$this->PrintParameters($argv);
				exit;
			}
			if($this->CreateDirectory($this->GetParameterValue('indir')) === FALSE) exit;
			if($this->CreateDirectory($this->GetParameterValue('outdir')) === FALSE) exit;
			if($this->GetParameterValue('graph_uri')) $this->SetGraphURI($this->GetParameterValue('graph_uri'));
			
		return TRUE;
	}

	function Run(){

		$this->connect_to_db();

		switch($this->GetParameterValue('files')) {
			case "compounds" :
				$this->process_compounds();
				break;
			case "targets":
				$this->process_targets();
				break;
			case "assays":
				$this->process_assays();
				break;
			case "references":
				$this->process_references();
				break;
			case "all":
				$this->all();
				break;
		}
	}

	/*
	*	download the mysql database dump from chembl
	*/
	function download(){
		//where to download the files
		$local_file = $this->GetParameterValue('outdir')."chembl_14_mysql.tar.gz";
		$remote_file = "chembl_14_mysql.tar.gz";

		$connection  = ftp_connect($this->GetParameterValue('download_uri'));
		$login_result = ftp_login($connection,"","");

		if(ftp_get($connection,$local_file,$remote_file)) {
			echo "successfully downloaded file to $local_file\n";
		} else {
			echo "There was a problem downloading the chembl mysql database file. \n";
		}

		ftp_close($connection);
	}

	/*
	*	Configure and load the mysql database
	*/
	function load_chembl_mysql() {
		//mysql -u username -p < dump/file/path/filename.sql
		$local_file = $this->GetParameterValue("outdir")."chembl_14_mysql.tar.gz";
		$dump_cmd = "mysql -u ".$this->GetParameterValue("user")." -p ".$this->GetParameterValue("pass")." ".$this->GetParameterValue("db_name")." < ".$local_file;
		
		if(shell_exec($dump_cmd)){
			echo "Successfully loaded the chembl mysql database.\n";
		} else {
			echo "There was a problem loading the chembl database into mysql.\n";
		}
	}

	function process_all(){
		$this->process_compounds();
		$this->process_targets();
		$this->process_assays();
		$this->process_references();
		$this->process_properties();
	}

	/*
	*	Process experimental data
	*		ACTIVITIES
	*/
	function process_activities(){

		$this->set_write_file("activities");

		$allIDs = mysql_query("SELECT DISTINCT * FROM activities" . $limit);

		while ($row = mysql_fetch_assoc($allIDs)) {
			$activity = "chembl:activity_".$row['activity_id'];
			$this->AddRDF($this->QQuad($activity,"rdf:type","chembl_vocabulary:Activity"));

			if ($row['doc_id'] != '-1') {
				$reference = "chembl:reference_".$row["doc_id"];
				$this->AddRDF($this->QQuad($activity,"chembl_vocabulary:citesAsDataSource",$reference));
			}

			$assay = "chembl:assay_".$row['assay_id'];
			$molecule = "chembl:compound_".$row['molregno'];

			$this->AddRDF($this->QQuad($activity,"chembl_vocabulary:assay",$assay));
			$this->AddRDF($this->QQuad($assay,"chembl_vocabulary:activity",$activity));
			$this->AddRDF($this->QQuad($activity,"chembl_vocabulary:molecule",$molecule));

			if ($row['standard_relation']) {
				$this->AddRDF($this->QQuadl($activity, "chembl_vocabulary:standard_relation",  $row['standard_relation'] ));
			}

			if ($row['standard_units']) {
				$this->AddRDF($this->QQuadl($activity, "chembl_vocabulary:standard_units",  $row['standards_units'] ));
			}

			if ($row['standard_value']) {
				$this->AddRDF($this->QQuadl($activity, "chembl_vocabulary:standard_value",  $row['standard_value'] ));
			}

			if ($row['standard_type']) {
				$this->AddRDF($this->QQuadl($activity, "chembl_vocabulary:standard_type",  $row['standard_type'] ));
			}

			if ($row['published_units']) {
				$this->AddRDF($this->QQuadl($activity, "chembl_vocabulary:published_units",  $row['published_units'] ));
			}
			
			if ($row['published_value']) {
				$this->AddRDF($this->QQuadl($activity, "chembl_vocabulary:published_value",  $row['published_value'] ));
			}

			if ($row['published_type']) {
				$this->AddRDF($this->QQuadl($activity, "chembl_vocabulary:published_type",  $row['published_type'] ));
			}

			if ($row['standard_flag']) {
				$this->AddRDF($this->QQuadl($activity, "chembl_vocabulary:standard_flag",  $row['standard_flag'] ));
			}

			if ($row['activity_comment']) {
				$this->AddRDF($this->QQuadl($activity, "rdfs:comment",  $row['activity_comment'] ));
			}

		}
	}
	/*
	*	Process Compound Information
	*/
	function process_compounds() {
		$this->set_write_file("compounds");

		$allIDs = mysql_query("SELECT DISTINCT molregno FROM molecule_dictionary");

		$num = mysql_numrows($allIDs);

		while ($row = mysql_fetch_assoc($allIDs)) {
		$molregno = $row['molregno'];
		$molecule = "chembl:compound_".$molregno;

		# get the literature references
		$refs = mysql_query("SELECT DISTINCT doc_id FROM compound_records WHERE molregno = $molregno");
		while ($refRow = mysql_fetch_assoc($refs)) {
			if ($refRow['doc_id'])
				$this->AddRDF($this->QQuad($molecule,"chembl_vocabulary:citesAsDataSource","chembl:reference_".$refRow['doc_id']));
		}

		# get the compound type, ChEBI, and ChEMBL identifiers
		$chebi = mysql_query("SELECT DISTINCT * FROM molecule_dictionary WHERE molregno = $molregno");
		while ($chebiRow = mysql_fetch_assoc($chebi)) {
			
			if ($chebiRow['molecule_type']) {
				$this->AddRDF($this->QQuadl($molecule,"chembl_vocabulary:molecule_type",$this->SafeLiteral($row['molecule_type'])));
			}

			if ($chebiRow['max_phase']) {
				$this->AddRDF($this->QQuadl($molecule,"chembl_vocabulary:max_phase",$this->SafeLiteral($row['max_phase'])));
			}

			if ($chebiRow['structure_type']) {
				$this->AddRDF($this->QQuadl($molecule,"chembl_vocabulary:structure_type",$this->SafeLiteral($row['structure_type'])));
			}

			if ($chebiRow['natural_product']) {
				$this->AddRDF($this->QQuadl($molecule,"chembl_vocabulary:natural_product",$this->SafeLiteral($row['natural_product'])));
			}

			if ($chebiRow['black_box_warning']) {
				$this->AddRDF($this->QQuadl($molecule,"chembl_vocabulary:black_box_warning",$this->SafeLiteral($row['black_box_warning'])));
			}

			$chebi = "chebi:".$chebiRow['chebi_id'];
			$chembl = "chembl:".$chebiRow['chembl_id'];
			$this->AddRDF($this->QQuad($molecule,"owl:equivalentClass",$chebi));
			$this->AddRDF($this->QQuad($chebi,"owl:equivalentClass",$molecule));
			$this->AddRDF($this->QQuad($molecule,"owl:equivalentClass",$chembl));
			$this->AddRDF($this->QQuad($chembl,"owl:equivalentClass",$molecule));
			$this->AddRDF($this->QQuad($chebi,"owl:equivalentClass",$chembl));

			// Add  human readable labels and bio2rdf requirements
			$this->AddRDF($this->QQuadl($chebi,"dc:identifier",$chebiRow['chebi_id']));
			$this->AddRDF($this->QQuadl($chembl,"dc:identifier",$chebiRow['chembl_id']));
		}
		
		# get the structure information
		$structs = mysql_query("SELECT DISTINCT * FROM compound_structures WHERE molregno = $molregno");
		while ($struct = mysql_fetch_assoc($structs)) {
			if ($struct['canonical_smiles']) {
			  $this->AddRDF($this->QQuadl($molecule,"chembl_vocabulary:smiles",$this->SafeLiteral($struct['canonical_smiles'])));
			}
			if ($struct['standard_inchi']) {
				$this->AddRDF($this->QQuadl($molecule,"chembl_vocabulary:standardInchi",$struct['standard_inchi']));
			}

			if ($struct['standard_inchi_key']) {
				$this->AddRDF($this->QQuadl($molecule,"chembl_vocabulary:standardInchiKey",$struct['standard_inchi_key']));
			}

			$this->WriteRDFBufferToWriteFile();
		}

		# get parent/child information
		$hierarchies = mysql_query("SELECT DISTINCT * FROM molecule_hierarchy WHERE molregno = $molregno");
			while ($hierarchy = mysql_fetch_assoc($hierarchies)) {
				if ($hierarchy['parent_molregno'] != $molregno) {
				  $parent = "chembl:".$hierarchy['parent_molregno'];
				  $this->AddRDF($this->QQuad($molecule,"chembl_vocabulary:hasParent",$parent));
				}
				if ($hierarchy['active_molregno'] != $molregno) {
				  $child = "chembl:".$hierarchy['active_molregno'];
				  $this->AddRDF($this->QQuad($molecule,"chembl_vocabulary:activeCompound",$child));
				}

				$this->WriteRDFBufferToWriteFile();
			}

		$this->WriteRDFBufferToWriteFile();

		}

		$this->GetWriteFile()->Close();
	}


	function set_write_file($name){
		 $write_file = $this->GetParameterValue("outdir").$name.".ttl";
		 echo $write_file."----> processing\n";
		 // set the compression
		 $gz=false;
		 if( $this->GetParameterValue('gzip')){
		 	$write_file.= ".gz";
		 	$gz=true;
		 }

		 $this->SetWriteFile($write_file,$gz);
	}

	/*
	* connect to the chembl database
	*/
	function connect_to_db(){
		$user = $this->GetParameterValue("user");
		$pwd = $this->GetParameterValue("pass");
		$db = $this->GetParameterValue('db_name');
		mysql_connect("127.0.0.1",$user,$pwd) or die(mysql_error()) ;
		mysql_select_db($db) or die(mysql_error());
	}

	/*
	* Process Target Information
	*/
	function process_targets() {

		$this->set_write_file("targets");

		$allIDs = mysql_query("SELECT DISTINCT * FROM target_dictionary, binding_sites where target_dictionary.tid == binding_sites.tid");

		$num = mysql_numrows($allIDs);

		while ($row = mysql_fetch_assoc($allIDs)) {

			$target = "chembl:target_". $row['tid'];
			$this->AddRDF($this->QQuad($target,"rdf:type","chembl_vocabulary:Target"));

			if ($row['target_type']) {
				$this->AddRDF($this->QQuad($target,"chembl_vocabulary:target_type","chembl:".$row['target_type']));
			}

			$chembl = "chembl:". $row['chembl_id'];

			$this->AddRDF($this->QQuad($chembl,"owl:equivalentClass",$target));
			$this->AddRDF($this->QQuad($target,"owl:equivalentClass",$chembl));
			$this->AddRDF($this->QQuadl($chembl,"dc:identifier",$row['chembl_id']));

			if ($row['tax_id']){
				$this->AddRDF($this->QQuad($target,"chembl_vocabulary:taxon","taxon:".$row['tax_id']));
			}

			if($row['pref_name']){
				$this->AddRDF($this->QQuadl($target,"chembl_vocabulary:pref_name",$this->SafeLiteral($row['pref_name'])));
			}

			if($row['site_id']){
				$binding_site = "chembl:bindingsite_".$row['site_id'];
				$this->AddRDF($this->QQuadl($target,"chembl_vocabulary:binding_site",$binding_site));
				$this->AddRDF($this->QQuadl($binding_site,"rdfs:label",$this->SafeLiteral($row['site_name'])))
			}

// SELECT TableA.*, TableB.*, TableC.*, TableD.*
// FROM TableA
//     JOIN TableB
//         ON TableB.aID = TableA.aID
//     JOIN TableC
//         ON Tableb.cID = TableB.cID
//     JOIN TableD
//         ON TableD.dID = TableA.dID
// WHERE DATE(TableC.date)=date(now()) 

			$components = mysql_query("select * from ")

		}


	}

	/*
	*	Parse experimental data tables
	*		ASSAYS
	*		ASSAY_TYPE
	*
	*	NOTE: UPDATED for v 15
	*/
	function process_assays() {

		$this->set_write_file("assays");

		$allIDs = mysql_query(
		    "SELECT DISTINCT * FROM assays, assay_type " .
		    "WHERE assays.assay_type = assay_type.assay_type"
		);

		$num = mysql_numrows($this->allIDs);

		while ($row = mysql_fetch_assoc($allIDs)) {

		  $assay = "chembl:assay_".$row['assay_id'];
		  $this->AddRDF($this->QQuad($assay,"rdf:type","chembl_vocabulary:Assay"));

		  // chembl assay id
		  $chembl = "chembl:". $row['chembl_id'];
		  $this->AddRDF($this->QQuadl($assay,"dc:identifier",$row['chembl_id']));
		  $this->AddRDF($this->QQuad($assay,"owl:equivalentClass",$chembl));
		  $this->AddRDF($this->QQuad($chembl,"owl:equivalentClass",$assay));
		  $this->WriteRDFBufferToWriteFile();

		  // DESCRIPTION
		  if ($row['description']) {
		    $this->AddRDF($this->QQuadl($assay,"chembl_vocabulary:description",$this->SafeLiteral($row['description'])));
		  }

		  // DOC ID
		  if ($row['doc_id']){
		  	$this->AddRDF($this->QQuad($assay,"chembl_vocabulary:doc_id","chembl:reference_".$row['doc_id']));
		  }		  

		  // TID : Target id
		  if ($row['tid']) {
		  	$chembl_target = "chembl:"."target_".$row['tid'];
		  	$this->AddRDF($this->QQuadl($assay,"chembl_vocabulary:target",$chembl_target));
		  }
			
		  // ASSAY_CATEGOry
		  if($row['assay_category']){
		  	$this->AddRDF($this->QQuadl($assay,"chembl_vocabulary:assay_category",$this->SafeLiteral($row['assay_category'])));
		  }

		  // ASSAY_TISSUE
		  if ($row['assay_tissue']){
		  		$this->AddRDF($this->QQuadl($assay,"chembl_vocabulary:assay_tissue",$this->SafeLiteral($row['asasy_tissue'])));
		  }

		  // ASSAY_CELL_TYPE
		  if($row['assay_cell_type']){
		  		$this->AddRDF($this->QQuadl($assay,"chembl_vocabulary:assay_cell_type",$this->SafeLiteral($row['assay_cell_type'])));
		  }

		  // SUBCELLULAR_FRACTION
		  if ($row['subcellular_fraction']){
		  	$this->AddRDF($this->QQuadl($assay,"chembl_vocabulary:subcellular_fraction",$this->SafeLiteral($row['subcellular_fraction'])));
		  }

		  // ASSAY_TEST_TYPE
		  if ($row['assay_test_type']){
		  	$this->AddRDF($this->QQuadl($assay,"chembl_vocabulary:assay_test_type",$this->SafeLiteral($row['assay_test_type'])));
		  }

		  $this->AddRDF($this->QQuad($assay,"chembl_vocabulary:assay_desc","chembl_vocabulary:".$row['assay_desc']));
		  $this->WriteRDFBufferToWriteFile();
		 
		}
	}

	/*
	* process references and information sources about assays
	* NOTE: Not updated for v15
	*/
	function process_references(){

		$this->set_write_file("references");

		$allIDs = mysql_query("SELECT DISTINCT journal FROM docs WHERE doc_id > 0 " . $limit);

		$num = mysql_numrows($allIDs);

		while ($row = mysql_fetch_assoc($allIDs)) {
		if (strlen($row['journal']) > 0) {
		//echo triple($JRN . "j" . md5($row['journal']), $RDF . "type", $BIBO . "Journal");
		//echo data_triple($JRN . "j" . md5($row['journal']), $DC . "title", $row['journal']);
		}
		}
		

		$allIDs = mysql_query("SELECT DISTINCT * FROM docs WHERE doc_id > 0 " . $limit);

		$num = mysql_numrows($allIDs);

		while ($row = mysql_fetch_assoc($allIDs)) {

			$reference = "chembl:reference_". $row['doc_id'];
			$this->AddRDF($this->QQuad($reference,"rdf:type","chembl_vocabulary:Article"));
			if ($row['doi']) {
				$this->AddRDF($this->QQuadl($reference,"chembl_vocabulary:hasDoi",$row['doi']));
			}

			if ($row['pubmed_id']) {
				$this->AddRDF($this->QQuadl($reference,"owl:equivalentClass","pmid:".$row['pubmed_id']));
			}

			$this->AddRDF($this->QQuadl($reference,"dc:date",$row['year'] ));
			$this->AddRDF($this->QQuadl($reference,"chembl_vocabulary:hasVolume",$row['volume'] ));
			$this->AddRDF($this->QQuadl($reference,"chembl_vocabulary:hasIssue",$row['issue'] ));
			$this->AddRDF($this->QQuadl($reference,"chembl_vocabulary:hasFirstPage",$row['first_page'] ));
			$this->AddRDF($this->QQuadl($reference,"chembl_vocabulary:hasLastPage",$row['last_page'] ));
			$this->AddRDF($this->QQuadl($reference,"chembl_vocabulary:hasJournal",$row['journal'] ));

			$this->WriteRDFBufferToWriteFile();
		}
	}

	function process_properties(){

		$this->set_write_file("properties");

		$allIDs = mysql_query("SELECT * FROM compound_properties " . $limit);

		$num = mysql_numrows($allIDs);

		# CHEMINF mappings
		$descs = array(
			"alogp" => "ALogP",
			"hba" => "Hba",
			"hbd" => "CHEMINF_000310",
			"psa" => "CHEMINF_000308",
			"rtb" => "CHEMINF_000311",
			"acd_most_apka" => "CHEMINF_000324",
			"acd_most_bpka" => "CHEMINF_000325",
			"acd_logp" => "CHEMINF_000321",
			"acd_logd" => "CHEMINF_000323",
			"num_ro5_violations" => "CHEMINF_000314",
			"ro3_pass" => "CHEMINF_000317",
			"med_chem_friendly" => "CHEMINF_000319",
			"full_mwt" => "CHEMINF_000198",
		);
		$descTypes = array(
			"alogp" => "double",
			"hba" => "nonNegativeInteger",
			"hbd" => "nonNegativeInteger",
			"psa" => "double",
			"rtb" => "nonNegativeInteger",
			"acd_most_apka" => "double",
			"acd_most_bpka" => "double",
			"acd_logp" => "double",
			"acd_logd" => "double",
			"num_ro5_violations" => "nonNegativeInteger",
			"ro3_pass" => "string",
			"med_chem_friendly" => "string",
			"full_mwt" => "double",
		);

		while ($row = mysql_fetch_assoc($allIDs)) {
			$molregno = $row['molregno'];
			$molecule = "chembl:". $molregno;

			foreach ($descs as $value => $p) {
				if ($row[$value]) {
					$molprop = "chembl:molprop_".md5($molecule.$row[$value]);
					$this->AddRDF($this->QQuad($molecule,"chembl_vocabulary:hasProperty",$molprop));
					$this->AddRDF($this->QQuad($molprop,"rdf:type","chembl_vocabulary:".$p));
					$this->AddRDF($this->QQuadl($molprop,"rdf:value",$row[$value]));
					
					// still need to add datatype
					//echo typeddata_triple($molprop, $CHEMINF . "SIO_000300", $row[$value], $XSD . $descTypes[$value] );
				}
			}

			$this->WriteRDFBufferToWriteFile();

		}
	}
}
set_error_handler('error_handler');
$parser = new ChemblParser($argv);
$parser->Run();

?>