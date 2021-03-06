#! /usr/bin/php
<?php
/**
Copyright (C) 2012 Michel Dumontier

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

require('../../php-lib/rdfapi.php');
/**
 * OMIM RDFizer (API version)
 * @version 1.0
 * @author Michel Dumontier
 * @description http://www.omim.org/help/api
*/
class OMIMParser extends RDFFactory
{
	private $version = null;
	
	function __construct($argv) {
		parent::__construct();
		$this->SetDefaultNamespace("omim");
		
		// set and print application parameters
		$this->AddParameter('files',true,null,'all|omim#','entries to process: comma-separated list or hyphen-separated range');
		$this->AddParameter('indir',false,null,'/data/download/'.$this->GetNamespace().'/','directory to download into and parse from');
		$this->AddParameter('outdir',false,null,'/data/rdf/'.$this->GetNamespace().'/','directory to place rdfized files');
		$this->AddParameter('graph_uri',false,null,null,'provide the graph uri to generate n-quads instead of n-triples');
		$this->AddParameter('gzip',false,'true|false','true','gzip the output');
		$this->AddParameter('download',false,'true|false','false','set true to download files');
		$this->AddParameter('download_url',false,null,'ftp://grcf.jhmi.edu/OMIM/');
		$this->AddParameter('omim_api_url',false,null,'http://api.omim.org/api/entry?include=all&format=json');
		$this->AddParameter('omim_api_key',false,null,'06402537D15CFD3880C99805B2AACA9858FAEE37','the OMIM key to download entries');
		if($this->SetParameters($argv) == FALSE) {
			$this->PrintParameters($argv);
			exit;
		}
		if($this->CreateDirectory($this->GetParameterValue('indir')) === FALSE) exit;
		if($this->CreateDirectory($this->GetParameterValue('outdir')) === FALSE) exit;
		if($this->GetParameterValue('graph_uri')) $this->SetGraphURI($this->GetParameterValue('graph_uri'));
		
		return TRUE;
	}
	
	function Run()
	{	
		// directory shortcuts
		$ldir = $this->GetParameterValue('indir');
		$odir = $this->GetParameterValue('outdir');

		// get the list of mim2gene entries
		$entries = $this->GetListOfEntries($ldir);
		
		// get the work specified
		$list = trim($this->GetParameterValue('files'));		
		if($list != 'all') {
			// check if a hyphenated list was provided
			if(($pos = strpos($list,"-")) !== FALSE) {
				$start_range = substr($list,0,$pos);
				$end_range = substr($list,$pos+1);
				
				// get the whole list
				$full_list = $this->GetListOfEntries($ldir);
				// now intersect
				foreach($full_list AS $e => $type) {
					if($e >= $start_range && $e <= $end_range) {
						$myentries[$e] = $type;
					}
				}
				$entries = $myentries;
			} else {
				// for comma separated list
				$b = explode(",",$this->GetParameterValue('files'));
				foreach($b AS $e) {
					$myentries[$e] = '';
				}
				$entries = array_intersect_key ($entries,$myentries);
			}		
		}
		
		// set the write file
		$outfile = 'omim.nt'; $gz=false;
		if($this->GetParameterValue('gzip')) {
			$outfile .= '.gz';
			$gz = true;
		}
		$this->SetWriteFile($odir.$outfile, $gz);
		
		// declare the mapping method types
		$this->get_method_type(null,true);
		
		// iterate over the entries
		$i = 0;
		$total = count($entries);
		foreach($entries AS $omim_id => $type) {
			echo "processing ".(++$i)." of $total - omim# ";
			$download_file = $ldir.$omim_id.".json";
			// download if the file doesn't exist or we are told to
			if(!file_exists($download_file) || $this->GetParameterValue('download') == 'true') {
				// download using the api
				$url = $this->GetParameterValue('omim_api_url').'&apiKey='.$this->GetParameterValue('omim_api_key').'&mimNumber='.$omim_id;
				$buf = file_get_contents($url);
				if(strlen($buf) != 0)  {
					file_put_contents($download_file, $buf);
					usleep(500000); // limit of 4 requests per second
				}
			}
			
			// load entry, parse and write to file
			$entry = json_decode(file_get_contents($download_file), true);
			$omim_id = trim((string)$entry["omim"]["entryList"][0]["entry"]['mimNumber']);
			echo $omim_id;
			$this->ParseEntry($entry,$type);
			$this->WriteRDFBufferToWriteFile();
		
			echo PHP_EOL;
		}
		$this->GetWriteFile()->Close();
		
		// generate the release file
		$this->DeleteBio2RDFReleaseFiles($odir);
		$desc = $this->GetBio2RDFDatasetDescription(
			$this->GetNamespace(), 
			"https://github.com/bio2rdf/bio2rdf-scripts/blob/master/omim/omim.php", 
			$this->GetBio2RDFDownloadURL($this->GetNamespace()).$outfile,
			"http://omim.org", 
			array("use","no-commercial"), 
			"http://omim.org/downloads",
			$this->GetParameterValue("download_url"),
			$this->version
		);
		$this->SetWriteFile($odir.$this->GetBio2RDFReleaseFile($this->GetNamespace()));
		$this->GetWriteFile()->Write($desc);
		$this->GetWriteFile()->Close();
		
		return TRUE;
	}
	
	function getListOfEntries($ldir)
	{
		// get the master list of entries
		$file = "mim2gene.txt";
		if(!file_exists($ldir.$file)) {
			trigger_error($ldir.$file." not found. Will attempt to download. ", E_USER_NOTICE);
			$this->SetParameterValue('download',true);
		}		
		
		if($this->GetParameterValue('download')==true) {
			// connect
			if(!isset($ftp)) {
				$host = 'grcf.jhmi.edu';
				echo "connecting to $host ...";
				$ftp = ftp_connect($host);
				if(!$ftp) {
					echo "Unable to connect to $host".PHP_EOL;
					die;
				}
				ftp_pasv ($ftp, true) ;				
				$login = ftp_login($ftp, 'anonymous', 'bio2rdf@gmail.com');
				if ((!$ftp) || (!$login)) { 
					echo "FTP-connect failed!"; die; 
				} else {
					echo "Connected".PHP_EOL;
				}
			}
				
			// download
			echo "Downloading $file ...";
			if(ftp_get($ftp, $ldir.$file, 'omim/'.$file, FTP_BINARY) === FALSE) {
				trigger_error("Error in downloading $file");
				continue;
			}
			if(isset($ftp)) ftp_close($ftp);
			echo "success!".PHP_EOL;
		}

		// parse the mim2gene file for the entries
		// # Mim Number    Type    Gene IDs        Approved Gene Symbols
		$fp = fopen($ldir.$file,"r");
		fgets($fp);
		while($l = fgets($fp)) {
			$a = explode("\t",$l);
			if($a[1] != "moved/removed")
				$list[$a[0]] = $a[1];
		}
		fclose($fp);
		return $list;
	}
	
	
	function get_phenotype_mapping_method_type($id = null, $generate_declaration = false)
	{
		$pmm = array(
			"1" => array("name"=>"association",
					"description" => "the disorder is placed on the map based on its association with a gene"),
			"2" => array("name" => "linkage",
					"description" => "the disorder is placed on the map by linkage"),
			"3" => array("name" => "mutation",
					"description" => "the disorder is placed on the map and a mutation has been found in the gene"),
			"4" => array("name" => "copy-number-variation",
					"description" => "the disorder is caused by one or more genes deleted or duplicated")
		);
		
		if($generate_declaration == true) {
			foreach($pmm AS $i => $o) {
				$pmm_uri = "omim_vocabulary:".$pmm[$i]['name'];
				$this->AddRDF($this->QQuadL($pmm_uri, "rdfs:label",$this->SafeLiteral($pmm[$pid]['description']." [$pmm_uri]")));
			}
		}
			
		if(isset($id)) {
			if(isset($pmm[$id])) return 'omim_vocabulary:'.$pmm[$id]['name'];
			else return false;
		}
		return true;
	}
	
	function get_method_type($id = null, $generate_declaration = false)
	{
		$methods = array(
			"A" => "in situ DNA-RNA or DNA-DNA annealing (hybridization)",
			"AAS" => "inferred from the amino acid sequence",
			"C" => "chromosome mediated gene transfer",
			"Ch" => "chromosomal change associated with phenotype and not linkage (Fc), deletion (D), or virus effect (V)",
			"D" => "deletion or dosage mapping, trisomy mapping, or gene dosage effects",
			"EM" => "exclusion mapping",
			"F" => "linkage study in families",
			"Fc" => "linkage study - chromosomal heteromorphism or rearrangement is one trait",
			"Fd" => "linkage study - one or both of the linked loci are identified by a DNA polymorphism",
			"H" =>  "based on presumed homology",
			"HS" => "DNA/cDNA molecular hybridization in solution (Cot analysis)",
			"L" => "lyonization",
			"LD" => "linkage disequilibrium",
			"M" => "Microcell mediated gene transfer",
			"OT" => "ovarian teratoma (centromere mapping)",
			"Pcm" => "PCR of microdissected chromosome segments (see REl)",
			"Psh" => "PCR of somatic cell hybrid DNA",
			"R" => "irradiation of cells followed by rescue through fusion with nonirradiated (nonhuman) cells (Goss-Harris method of radiation-induced gene segregation)",
			"RE" => "Restriction endonuclease techniques",
			"REa" => "combined with somatic cell hybridization",
			"REb" => "combined with chromosome sorting",
			"REc" => "hybridization of cDNA to genomic fragment (by YAC, PFGE, microdissection, etc.)",
			"REf" => "isolation of gene from genomic DNA; includes exon trapping",
			"REl" => "isolation of gene from chromosome-specific genomic library (see Pcm)",
			"REn" => "neighbor analysis in restriction fragments",
			"S" => "segregation (cosegregation) of human cellular traits and human chromosomes (or segments of chromosomes) in particular clones from interspecies somatic cell hybrids",
			"T" => "TACT telomere-associated chromosome fragmentation",
			"V" => "induction of microscopically evident chromosomal change by a virus",
			"X/A" => "X-autosome translocation in female with X-linked recessive disorder"
		);
		if($generate_declaration == true) {
			foreach($methods AS $k => $v) {
				$method_uri = $this->GetVocabularyNamespace().":$k";
				$this->AddRDF($this->QQuadL($method_uri, "rdfs:label", $methods[$k]." [$method_uri]"));
			}
		}
		
		if(isset($id)) {
			if(isset($methods[$id])) return $this->GetVocabularyNamespace().":$id";
			else return false;
		}
		return true;
	}	
	
	
	function ParseEntry($obj, $type)
	{
		$o = $obj["omim"]["entryList"][0]["entry"];
		$omim_id = $o['mimNumber'];
		$omim_uri = "omim:".$o['mimNumber'];
		if(isset($o['version']) && !isset($this->version)) $this->version = $o['version'];
		
		// add the type info
		$this->AddRDF($this->QQuad($omim_uri, "rdf:type", "omim_vocabulary:".str_replace("/","-", ucfirst($type))));
		$this->AddRDF($this->QQuad($omim_uri,"void:inDataset",$this->GetDatasetURI()));
		$this->AddRDF($this->QQuadO_URL($omim_uri, "rdfs:seeAlso", "http://omim.org/entry/".$omim_id));
		$this->AddRDF($this->QQuadO_URL($omim_uri, "owl:sameAs",   "http://identifiers.org/omim/".$omim_id));

		
		// parse titles
		$titles = $o['titles'];
		if(isset($titles['preferredTitle'])) {
			$this->AddRDF($this->QQuadL($omim_uri, "rdfs:label", $this->SafeLiteral($titles['preferredTitle'])." [$omim_uri]"));
			$this->AddRDF($this->QQuadL($omim_uri, "omim_vocabulary:preferred-title", $this->SafeLiteral($titles['preferredTitle'])));
		}
		if(isset($titles['alternativeTitles'])) {
			$b = explode(";;",$titles['alternativeTitles']);
			foreach($b AS $title) {
				$this->AddRDF($this->QQuadL($omim_uri, "omim_vocabulary:alternative-title", $this->SafeLiteral(trim($title))));
			}
		}	

		// parse text sections
		if(isset($o['textSectionList'])) {
			foreach($o['textSectionList'] AS $i => $section) {
			
				if($section['textSection']['textSectionTitle'] == "Description") {
					$this->AddRDF($this->QQuadL($omim_uri, "dc:description", $this->SafeLiteral($section['textSection']['textSectionContent'])));	
				} else {
					$p = str_replace(" ","-", strtolower($section['textSection']['textSectionTitle']));
					$this->AddRDF($this->QQuadL($omim_uri, "omim_vocabulary:$p", $this->SafeLiteral($section['textSection']['textSectionContent'])));	
				}
				
				// parse the omim references
				preg_match_all("/\(([0-9]{6})\)/",$section['textSection']['textSectionContent'],$m);
				if(isset($m[1][0])) {
					foreach($m[1] AS $oid) {
						$this->AddRDF($this->QQuad($omim_uri, "omim_vocabulary:refers-to", "omim:$oid" ));
					}
				}
			}
		}
		
		// allelic variants
		if(isset($o['allelicVariantList'])) {
			foreach($o['allelicVariantList'] AS $i => $v) {
				$v = $v['allelicVariant'];
			
				$uri = "omim_resource:$omim_id"."_allele_".$i;
				$label = str_replace("\n"," ",$v['name']);
				
				$this->AddRDF($this->QQuadL($uri, "rdfs:label", $label." [$uri]" ));
				$this->AddRDF($this->QQuad($uri, "rdf:type", "omim_vocabulary:Allelic-Variant"));
				$this->AddRDF($this->QQuad($uri,"void:inDataset",$this->GetDatasetURI()));

				if(isset($v['alternativeNames'])) {
					$names = explode(";;",$v['alternativeNames']);
					foreach($names AS $name) {
						$name = str_replace("\n"," ",$name);
						$this->AddRDF($this->QQuadL($uri,"omim_vocabulary:alternative-names",$name));				
					}
				}
				if(isset($v['text'])) $this->AddRDF($this->QQuadL($uri,"dc:description",$this->SafeLiteral($v['text'])));
				if(isset($v['mutations'])) $this->AddRDF($this->QQuadL($uri,"omim_vocabulary:mutation",$this->SafeLiteral($v['mutations'])));				
				if(isset($v['dbSnps'])) {
					$this->AddRDF($this->QQuad($uri, "omim_vocabulary:dbsnp", "dbsnp:".$v['dbSnps']));
				}
				$this->AddRDF($this->QQuad($omim_uri, "omim_vocabulary:variant", $uri));
			}
		}
		
		// clinical synopsis
		if(isset($o['clinicalSynopsis'])) {
			$cs = $o['clinicalSynopsis'];
			$uri = "omim_resource:".$omim_id."_cs";
			$this->AddRDF($this->QQuad($omim_uri, "omim_vocabulary:clinical-synopsis", $uri));
			$this->AddRDF($this->QQuadL($uri, "rdfs:label", "Clinical synopsis for omim $omim_id [$uri]"));
			$this->AddRDF($this->QQuad($uri, "rdf:type", "omim_vocabulary:Clinical-Synopsis"));
			$this->AddRDF($this->QQuad($uri,"void:inDataset",$this->GetDatasetURI()));

			foreach($cs AS $k => $v) {
				if(!strstr($k,"Exists")) { // ignore the boolean assertion.
					
					// ignore provenance for now
					if(in_array($k, array('contributors','creationDate','editHistory','epochCreated','dateCreated','epochUpdated','dateUpdated'))) continue;
					
					if(!is_array($v)) $v = array($k=>$v);
					foreach($v AS $k1 => $v1) {
						$phenotypes = explode(";",$v1);
						foreach($phenotypes AS $coded_phenotype) {
							// parse out the codes
							$coded_phenotype = trim($coded_phenotype);
							if(!$coded_phenotype) continue;
							$phenotype = preg_replace("/\{.*\}/","",$coded_phenotype);
							$phenotype_id = "omim_resource:".md5(strtolower($phenotype));
							
							$this->AddRDF($this->QQuad($uri, "omim_vocabulary:feature", $phenotype_id));
							$this->AddRDF($this->QQuadL($phenotype_id, "rdfs:label", $this->SafeLiteral($phenotype)." [$phenotype_id]"));
							$this->AddRDF($this->QQuad($phenotype_id, "rdf:type", 'omim_vocabulary:Characteristic'));
							$this->AddRDF($this->QQuad($phenotype_id,"void:inDataset",$this->GetDatasetURI()));
							
							$entity_id = "omim_resource:".$k1;
							$this->AddRDF($this->QQuadL($entity_id, "rdfs:label", "$k1 [$entity_id]"));
							$this->AddRDF($this->QQuad($entity_id,  "rdf:type", "omim_vocabulary:Entity"));
							$this->AddRDF($this->QQuad($entity_id,  "void:inDataset",$this->GetDatasetURI()));

							$this->AddRDF($this->QQuad($phenotype_id, "omim_vocabulary:characteristic-of", $entity_id));
							
							// parse out the vocab references
							preg_match_all("/\{([0-9A-Za-z \:\-\.]+)\}|;/",$coded_phenotype,$codes);
							//preg_match_all("/((UMLS|HP|SNOMEDCT|ICD10CM|ICD9CM)\:[A-Z0-9]+)/",$coded_phenotype,$m);
							if(isset($codes[1][0])) {
								foreach($codes[1] AS $entry) {
									$entries = explode(" ",trim($entry));
									foreach($entries AS $e) {
										$this->GetNS()->ParsePrefixedName($e,$ns,$id);
										if(!isset($ns) || $ns == '') {
											if($id == "HPO") continue;
											$b = explode(".",$id);
											$ns = "omim"; 
											$id = $b[0];
										} else {
											$ns = str_replace(array("icd10cm","icd9cm","snomedct"), array("icd10","icd9","snomed"), $ns);
										}
										$this->AddRDF($this->QQuad($phenotype_id, "rdfs:seeAlso", $ns.":".$id));
										$this->AddRDF($this->QQuad($uri, "omim_vocabulary:refers-to", $ns.":".$id));										
									} // foreach
								} // foreach
							} // codes
						} //foreach			
					} // foreach
				} // exists
			}
		} // clinical synopsis
		
		// genemap
		if(isset($o['geneMap'])) {
			$map = $o['geneMap'];
			if(isset($map['chromosome'])) {
				$this->AddRDF($this->QQuadL($omim_uri, "omim_vocabulary:chromosome", $map['chromosome']));
			}
			if(isset($map['cytoLocation'])) {
				$this->AddRDF($this->QQuadL($omim_uri, "omim_vocabulary:cytolocation", $map['cytoLocation']));
			}
			if(isset($map['geneSymbols'])) {
				$b = preg_split("/[,;\. ]+/",$map['geneSymbols']);
				foreach($b AS $symbol) {
					$this->AddRDF($this->QQuad($omim_uri, "omim_vocabulary:gene-symbol", "symbol:".trim($symbol)));
				}
			}
			
			
			if(isset($map['geneName'])) {
				$b = explode(",",$map['geneName']);
				foreach($b AS $name) {
					$this->AddRDF($this->QQuadL($omim_uri, "omim_vocabulary:gene-name", trim($name)));
				}
			}
			if(isset($map['mappingMethod'])) {
				$b = explode(",",$map['mappingMethod']);
				foreach($b AS $c) {
					$mapping_method = trim($c);
					$method_uri = $this->get_method_type($mapping_method);
					if($method_uri !== false)
						$this->AddRDF($this->QQuad($omim_uri, "omim_vocabulary:mapping-method", $method_uri));
					
				}
			}
			
			if(isset($map['mouseGeneSymbol'])) {
				$this->AddRDF($this->QQuad($omim_uri, "omim_vocabulary:mouse-gene-symbol", "symbol:".$map['mouseGeneSymbol']));
			}
			if(isset($map['mouseMgiID'])) {
				$this->AddRDF($this->QQuad($omim_uri, "omim_vocabulary:mouse-mgi", strtolower($map['mouseMgiID'])));
			}
			if(isset($map['geneInheritance']) && $map['geneInheritance'] != '') {
				$this->AddRDF($this->QQuadL($omim_uri, "omim_vocabulary:gene-inheritance", $map['geneInheritance']));
			}			
			if(isset($map['phenotypeMapList'])) {
				foreach($map['phenotypeMapList'] AS $phenotypeMap) {
					$phenotypeMap = $phenotypeMap['phenotypeMap'];
					if(isset($phenotypeMap['phenotypeMimNumber']))			
						$this->AddRDF($this->QQuad($omim_uri, "omim_vocabulary:phenotype", "omim:".$phenotypeMap['phenotypeMimNumber']));
						
					// $pmmt = get_phenotype_mapping_method_type($phenotype_map['phenotypeMappingKey']
				}
			}		
		}
		
		// references
		if(isset($o['referenceList'])) {
			foreach($o['referenceList'] AS $i => $r) {
				$r = $r['reference'];
				if(isset($r['pubmedID'])) {
					$pubmed_uri = "pubmed:".$r['pubmedID'];
					$this->AddRDF($this->QQuad($omim_uri, "omim_vocabulary:article", $pubmed_uri)); 
					if(isset($r['title']))      $this->AddRDF($this->QQuadL($pubmed_uri, "rdfs:label", addslashes($r['title'])." [$pubmed_uri]")); 
					if(isset($r['articleUrl'])) $this->AddRDF($this->QQuadO_URL($pubmed_uri, "rdfs:seeAlso", htmlentities($r['articleUrl']))); 
				}
			}
		}
	
		// external ids
		if(isset($o['externalLinks'])) {
			foreach($o['externalLinks'] AS $k => $id) {

				$ns = '';
				switch($k) {
					case 'approvedGeneSymbols':        $ns = 'symbol';break;
					case 'geneIDs':                    $ns = 'geneid';break;
					case 'ncbiReferenceSequences':     $ns = 'gi';break;
					case 'genbankNucleotideSequences': $ns = 'gi';break;
					case 'proteinSequences':           $ns = 'gi';break;
					case 'uniGenes':                   $ns = 'unigene';break;
					case 'ensemblIDs':                 $ns = 'ensembl';break;
					case 'swissProtIDs':               $ns = 'uniprot';break;
					case 'mgiIDs':                     $ns = 'mgi';$b = explode(":",$id);$id=$b[1];break;
					
					case 'flybaseIDs':                 $ns = 'flybase';break;
					case 'zfinIDs':                    $ns = 'zfin';break;
					case 'hprdIDs':                    $ns = 'hprd';break;
					case 'orphanetDiseases':           $ns = 'orphanet';break;
					case 'refSeqAccessionIDs':         $ns = 'refseq';break;
					case 'ordrDiseases':               $ns = 'ordr';$b=explode(";;",$id);$id=$b[0];break;
					
					case 'snomedctIDs':                $ns = 'snomed';break;
					case 'icd10cmIDs':                 $ns = 'icd10';break;
					case 'icd9cmIDs':                  $ns = 'icd9';break;
					case 'umlsIDs':                    $ns = 'umls';break;
					case 'wormbaseIDs':                $ns = 'wormbase';break;
					
					
					// specifically ignorning
					case 'newbornScreeningUrls':  
					case 'decipherUrls':
					case 'geneReviewShortNames':      
					case 'locusSpecificDBs':
					case 'geneticsHomeReferenceIDs':   					
					case 'omiaIDs':                   
					case 'coriellDiseases': 
					case 'clinicalDiseaseIDs':        
					case 'possumSyndromes':
					case 'mgiHumanDisease':
					case 'dermAtlas':                  // true/false
						break;
					
					default:
						echo "external link $k $id".PHP_EOL;
				}
			
			
				$ids = explode(",",$id);
				foreach($ids AS $id) {
					if($ns) {
						$b = explode(";;",$id); // multiple ids//names
						foreach($b AS $c) {
							if(is_numeric($c) == TRUE) {
								$this->AddRDF($this->QQuad($omim_uri, "omim_vocabulary:xref", $ns.':'.$c)); 
						}}
					}
				}
			}
		} //external links

	} // end parse
} 

set_error_handler('error_handler');
$parser = new OMIMParser($argv);
$parser->Run();
?>
