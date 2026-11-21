<?php

// Compression/JSON formatting adapted from RRMod_PuzzleFix (MIT).
// Copyright (c) 2025 Rushin. See LICENSE and THIRD_PARTY_NOTICES.md.
// Kept separate from upstream config, player-save and rendering modules.

function OverlayAssetMessage(string $message, mixed ...$unused): string { return $message; }

function OverlayAssetWrite(string $path, string $data, bool $announce = false): void
{
    if (!is_dir(dirname($path))) { throw new RuntimeException('Asset output parent directory does not exist.'); }
    if ($announce) { printf("Writing %s...\n", $path); }
    if (file_put_contents($path, $data) !== strlen($data)) { throw new RuntimeException('Cannot write asset JSON.'); }
}

function CreateJson($data, array $options = []){
	$defaultOptions = [
		"spaces"  => 4,
		"tabs" => 1,
		"tabOffset" => 0,
		"useTabs" => true,
		"escapeSlashes" => true,
		"prettyPrint" => true,
		//"onePerLine" => false,
		"flags" => 0x00,
	];
	$options   = array_merge($defaultOptions, $options);
	$spaces    = $options["spaces"];
	$tabs      = $options["tabs"];
	$tabOffset = $options["tabOffset"];
	$useTabs   = (bool)$options["useTabs"];
	$escapeSlashes = (bool)$options["escapeSlashes"];
	$prettyPrint = (bool)$options["prettyPrint"];
	$givenFlags   = $options["flags"];
	
	//$final = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	// Magic (and slow) function that prints a json, except with 2 spaces for tabs instead of 4 (which is what the asset dumper does).
	$char = ($useTabs ? "\t" : " ");
	$padLen = ($useTabs ? $tabs : $spaces);
	$flags = $givenFlags | ($escapeSlashes ? JSON_UNESCAPED_SLASHES : 0x00) | ($prettyPrint ? JSON_PRETTY_PRINT : 0x00);
	$final = preg_replace_callback('/^(?: {4})+/m', function($m) use ($char, $padLen, $tabOffset) {
		return str_repeat($char, $padLen * (max(0, strlen($m[0]) / 4 + $tabOffset)));
		//}, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		}, json_encode($data, $flags));
	return $final;
}

$g_uassetExportIndex = 0;
$g_uassetCompressionSettings = [];
function CompressUassetValueNode(&$node_ref){
	global $g_uassetExportIndex;
	global $g_uassetCompressionSettings;
	
	$type = $node_ref->{'$type'} ?? "";
	$name = $node_ref->{'Name'} ?? "";
	
	if(is_object($node_ref)){
		unset($node_ref->DuplicationIndex);
		unset($node_ref->IsZero);
		unset($node_ref->StructGUID);
		
		if(isset($node_ref->DummyStruct)){ // do NOT skip this check
			unset($node_ref->DummyStruct->DuplicationIndex);
			unset($node_ref->DummyStruct->IsZero);
			unset($node_ref->DummyStruct->StructGUID);
		}
		//unset($node_ref->DummyStruct); // do NOT do this
		
		$valueExists = (in_array("Value", array_keys((array)$node_ref))); // can't isset
		if($valueExists){
			// Move Value field over to the end of the object.
			$a = $node_ref->Value; unset($node_ref->Value); $node_ref->Value = $a; unset($a);
		}
		$v = $node_ref->Value ?? null;
		// Oh my god what have I been even doing here
		$isTextType     = ($type == "UAssetAPI.PropertyTypes.Objects.TextPropertyData, UAssetAPI");
		$isDelegateType = ($type == "UAssetAPI.PropertyTypes.Objects.FDelegate, UAssetAPI");
		$isEmptyArray   = (is_array($v) && empty($v));
		$isBadString    = (is_string($v) && strpbrk($v, "|\"\\"));
		if(($valueExists && !$isTextType && ($v == null || (is_scalar($v) && !$isBadString))) || $isDelegateType){
		//if($valueExists && $type != "UAssetAPI.PropertyTypes.Objects.TextPropertyData, UAssetAPI" && ($v == null || (is_array($v) && empty($v)) || (is_scalar($v) && ((!is_string($v) || !strpbrk($v, "|\"\\")))))){
			//printf("Trimming %s\n", substr(json_encode($node_ref, JSON_UNESCAPED_SLASHES), 0, 100));
			$node_ref = "SCALAR_STR_BEGIN{ " . substr(str_replace("\"", "|", str_replace("\",\"", "\", \"", json_encode($node_ref, JSON_UNESCAPED_SLASHES))), 1, -1) . " }SCALAR_STR_END";
			$node_ref = str_replace("|:null,|", "|:null, |", $node_ref); // no idea why but null values don't get a space after the comma in json_encode?!
			return;
		}
		
	}elseif(is_array($node_ref)){
		foreach($node_ref as &$subNode_ref){
			CompressUassetValueNode($subNode_ref);
		}
		return;
	}
	
	switch($type){
		case "UAssetAPI.UAsset, UAssetAPI":
			// Parent node.
			// Move Exports field over to the end of the object.
			$a = $node_ref->Exports; unset($node_ref->Exports); $node_ref->Exports = $a; unset($a);
			// Everything that isn't Exports should be a one-liner. Note: iterating an object here.
			foreach($node_ref as $key => &$value_ref){
				if(is_scalar($value_ref) || $key == "Exports"){
					// Do nothing with scalar values, and ignore Exports for now.
					continue;
				}
				if($key == "Imports"){
					foreach($value_ref as $i => &$subValue_ref){
						// Add import index after type, compress each import to one line.
						$type  = $subValue_ref->{'$type'};
						$outer = $subValue_ref->{'OuterIndex'};
						unset($subValue_ref->{'$type'});
						unset($subValue_ref->{'$index'});
						unset($subValue_ref->{'OuterIndex'});
						//if(property_exists($subValue_ref, "PackageName") && $subValue_ref->PackageName == null){
						//	unset($subValue_ref->PackageName); // uasset crash
						//}
						//if(property_exists($subValue_ref, "bImportOptional") && $subValue_ref->bImportOptional == false){
						//	unset($subValue_ref->bImportOptional); // uasset crash
						//}
						$subValue_ref = (object)array_merge([ '$type' => $type, '$index' => -($i + 1), 'OuterIndex' => $outer ], (array)$subValue_ref);
						$subValue_ref = "META_STR_BEGIN" . str_replace("\"", "|", json_encode($subValue_ref, JSON_UNESCAPED_SLASHES)) . "META_STR_END";
					}unset($subValue_ref);
					continue;
				}
				// Compress other non-scalar values to one-liners.
				$value_ref = "META_STR_BEGIN" . str_replace("\"", "|", json_encode($value_ref, JSON_UNESCAPED_SLASHES)) . "META_STR_END";
				//if($key != "Exports" && !is_scalar($value_ref)){
				//	$value_ref = "META_STR_BEGIN" . str_replace("\"", "|", json_encode($value_ref, JSON_UNESCAPED_SLASHES)) . "META_STR_END";
				//}
			}unset($value_ref);
			CompressUassetValueNode($node_ref->Exports);
			return;
		
		case "UAssetAPI.ExportTypes.LevelExport, UAssetAPI":
		case "UAssetAPI.ExportTypes.NormalExport, UAssetAPI":
		case "UAssetAPI.ExportTypes.ClassExport, UAssetAPI":
		case "UAssetAPI.ExportTypes.FunctionExport, UAssetAPI":
		case "UAssetAPI.ExportTypes.RawExport, UAssetAPI":
			// Move Data field over to the end of the object.
			$a = $node_ref->Data; unset($node_ref->Data); $node_ref->Data = $a; unset($a);
			// Everything that isn't Data should be a one-liner. Note: iterating an object here.
			foreach($node_ref as $key => &$value_ref){
				if(!is_scalar($value_ref) && !in_array($key, [ "Data", ])){ //"Actors", "NotActors" ])){
					$value_ref = "META_STR_BEGIN" . str_replace("\"", "|", json_encode($value_ref, JSON_UNESCAPED_SLASHES)) . "META_STR_END";
				}
			}unset($value_ref);
			CompressUassetValueNode($node_ref->Data);
			// Append $index field after $type.
			++$g_uassetExportIndex;
			$type = $node_ref->{'$type'};
			$customIndex = ($node_ref->{'$index'} ?? $g_uassetExportIndex);
			unset($node_ref->{'$type'});
			unset($node_ref->{'$index'});
			$node_ref = (object)array_merge([ '$type' => $type, '$index' => $customIndex ], (array)$node_ref);
			return;
			
		case "UAssetAPI.PropertyTypes.Objects.SetPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.ArrayPropertyData, UAssetAPI":
			$isStruct = (isset($node_ref->ArrayType) && $node_ref->ArrayType == "StructProperty");
			// TODO sort by Name field (actually index) and make sure it's sequential (0..count-1).
			//foreach($node_ref->Value as $index => &$subNode_ref){
			//	if(!$isStruct && $g_uassetCompressionSettings["skipArrayIndices"]){
			//		unset($subNode_ref->Name); // remove array indices.
			//	}
			//	CompressUassetValueNode($subNode_ref);
			//}unset($subNode_ref);
			
			foreach($node_ref->Value as $index => &$subNode_ref){
				if(!$isStruct){// && $g_uassetCompressionSettings["skipArrayIndices"]){
					if($g_uassetCompressionSettings["skipArrayIndices"]){
						unset($subNode_ref->Name); // remove array indices.
					}elseif(!isset($subNode_ref->Name)){
						$subNode_ref->Name = sprintf("%d", $index); // bring back array indices.
					}
				}
				CompressUassetValueNode($subNode_ref);
			}unset($subNode_ref);	
			
			//if($name == "Solves" && $isStruct){
			if(in_array($name, $g_uassetCompressionSettings["scalarizeNodes"]) && $isStruct){
				//$node_ref->Value = "SOLVES_STR_BEGIN" . str_replace("\"", "|", json_encode($node_ref->Value, JSON_UNESCAPED_SLASHES)) . "SOLVES_STR_END"; // this works!
				// Turn to a string.
				$node_ref->Value = json_encode($node_ref->Value, JSON_UNESCAPED_SLASHES);
				// Remove scalar markers.
				$node_ref->Value = preg_replace_callback("/\"SCALAR_STR_BEGIN(.*?)SCALAR_STR_END\"/", function ($matches) { return (str_replace("|", "\"", $matches[1])); }, $node_ref->Value);
				// Re-encode as a very compressed one-liner json.
				$node_ref->Value = json_encode(json_decode($node_ref->Value), JSON_UNESCAPED_SLASHES);
				// Turn into a temporary string so that it's not expanded back later.
				$node_ref->Value = "SOLVES_STR_BEGIN" . str_replace("\"", "|", $node_ref->Value) . "SOLVES_STR_END";
			}
			return;
			
		case "UAssetAPI.PropertyTypes.Structs.StructPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.MulticastSparseDelegatePropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.MapPropertyData, UAssetAPI":
			CompressUassetValueNode($node_ref->Value);
			return;
			
		case "UAssetAPI.PropertyTypes.Objects.BoolPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.EnumPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.IntPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.FloatPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.StrPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.ObjectPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.NamePropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.TextPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.GuidPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.ColorPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.BoxPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.QuatPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.Vector2DPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.VectorPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.Vector4PropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.RotatorPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.SoftObjectPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.SoftClassPathPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.BytePropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.DelegatePropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.FDelegate, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.LinearColorPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.RichCurveKeyPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.SoftObjectPathPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.IntPointPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.UnknownPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.MulticastInlineDelegatePropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.NiagaraDataInterfaceGPUParamInfoPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.MovieSceneFrameRangePropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.MovieSceneEvaluationFieldEntityTreePropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.FrameNumberPropertyData, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Structs.MovieSceneFloatChannelPropertyData, UAssetAPI":
		//case "UAssetAPI.ExportTypes.RawExport, UAssetAPI":
			return;
			
		//case "UAssetAPI.ExportTypes.FunctionExport, UAssetAPI":
		//case "UAssetAPI.ExportTypes.ClassExport, UAssetAPI":
		//	// I don't know what to do with these for now. Probably not something to edit by hand anyway?
		//	// Turn each field into one-liners for now. Maybe the entire export node should be a one-liner.
		//	foreach($node_ref as $key => &$value_ref){
		//		if(!is_scalar($value_ref)){
		//			$value_ref = "META_STR_BEGIN" . str_replace("\"", "|", json_encode($value_ref, JSON_UNESCAPED_SLASHES)) . "META_STR_END";
		//		}
		//	}unset($value_ref);
		//	// Append $index field after $type.
		//	++$g_uassetExportIndex;
		//	$type = $node_ref->{'$type'};
		//	$customIndex = ($node_ref->{'$index'} ?? $g_uassetExportIndex);
		//	unset($node_ref->{'$type'});
		//	unset($node_ref->{'$index'});
		//	$node_ref = (object)array_merge([ '$type' => $type, '$index' => $customIndex ], (array)$node_ref);
		//	return;
		
		case "System.Byte[], System.Private.CoreLib":
		case "UAssetAPI.CustomVersion, UAssetAPI":
		case "UAssetAPI.ExportTypes.FURL, UAssetAPI":
		case "UAssetAPI.FEngineVersion, UAssetAPI":
		case "UAssetAPI.FGenerationInfo, UAssetAPI":
		case "UAssetAPI.Import, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.FSoftObjectPath, UAssetAPI":
		case "UAssetAPI.PropertyTypes.Objects.FTopLevelAssetPath, UAssetAPI":
		case "UAssetAPI.UnrealTypes.FQuat, UAssetAPI":
		case "UAssetAPI.UnrealTypes.FRotator, UAssetAPI":
		case "UAssetAPI.UnrealTypes.FVector, UAssetAPI":
		case "UAssetAPI.UnrealTypes.TBox`1[[UAssetAPI.UnrealTypes.FVector, UAssetAPI]], UAssetAPI":
			// No idea how to handle for now.
	}
	
	printf("\nERROR: did not parse |%s|:\n%s\n\n", $type, json_encode($node_ref));
	exit(1);
}

function SaveCompressedDecodedUasset(string $path, &$json, array $options = []){
	
	global $g_uassetExportIndex;
	global $g_uassetCompressionSettings;
	
	if(empty($json) || !isset($json->Exports)){
		printf(OverlayAssetMessage(sprintf("[ERROR] Cannot compress %s\n", $path), 255, 128, 128));
		exit(1);
	}
	
	$tabOffsetCount = -2;
	if(count($json->Exports) == 1){
		$tabOffsetCount = -4; // don't ask
	}
	$defaultOptions = [
		// Compression settings.
		"skipArrayIndices" => false, // for arrays and sets, remove the Name field from each entry. shorter json, but uassetgui bugs out (still loads/saves fine though).
		"scalarizeNodes" => [ ], // enforce some nodes to be as short as possible; mainly Solves in PuzzleDatabase
		
		// Indexing stuff.
		"bakeAllIndices" => false, // replace custom text indices with programmatically enumerated ones
		"bakeManualIndices" => false,
		"bakeDefaultIndices" => false,
		"buildDefaultIndices" => false,
		"defaultIndexPrefix" => "%i",
		"defaultIndexSeparator" => "_",
		"manualIndexPrefix" => "#",
		
		// Automated fixes.
		"bakeAutoObjectNames" => true, // replace ObjectName like BP_RunePrereqWire_C_#AUTO with programmatically enumerated names
		"addObjectNamesToNameMap" => true, // at least partially automate NameMap shenanigans
		"simplifyImports" => false, // remove or append if  missing some default fields to Imports
		
		// Json formatting.
		"tabs" => 1,
		"tabOffset" => $tabOffsetCount,
		"useTabs" => true,
		"escapeSlashes" => true,
		"prettyPrint" => true,
		
		// Debugging.
		"verbose" => true,
	];
	
	$g_uassetExportIndex = 0;
	$g_uassetCompressionSettings = array_merge($defaultOptions, $options);
	
	if($g_uassetCompressionSettings["verbose"]){ printf("Compressing json for %s...\n", $path); }
	
	$copy = unserialize(serialize($json));
	//printf("Default:\n%s\n\n", json_encode($defaultOptions, JSON_PRETTY_PRINT));
	//printf("Given:\n%s\n\n", json_encode($options, JSON_PRETTY_PRINT));
	//printf("Merged:\n%s\n\n", json_encode($g_uassetCompressionSettings, JSON_PRETTY_PRINT));
	
	foreach($copy->Imports as $ii => &$import_ref){
		$importIndex = -($ii + 1);
		if($g_uassetCompressionSettings["simplifyImports"]){
			if(property_exists($import_ref, "PackageName") && $import_ref->PackageName != null){
				printf("[ERROR] I will not remove a non-default PackageName value from import:%\s%s\n", json_encode($import_ref));
				exit(1);
			}
			unset($import_ref->PackageName);
			if(property_exists($import_ref, "bImportOptional") && $import_ref->bImportOptional != null){
				printf("[ERROR] I will not remove a non-default bImportOptional value from import:%\s%s\n", json_encode($import_ref));
				exit(1);
			}
			unset($import_ref->bImportOptional);
		}else{
			if(!isset($import_ref->PackageName)){
				$import_ref->PackageName = null;
			}
			if(!isset($import_ref->bImportOptional)){
				$import_ref->bImportOptional = false;
			}
		}
	}unset($import_ref);
	
	// First, let's fix object names.
	if($g_uassetCompressionSettings["bakeAutoObjectNames"]){
		$objectCountMap = [];
		// We need to collect the counts of similar object names.
		foreach($copy->Exports as $i => &$node_ref){
			if(isset($node_ref->ObjectName) && preg_match("/^(.*?)_(\d+)$/", $node_ref->ObjectName, $matches)){
				$tempName = $matches[1];
				$tempCount = intval($matches[2]);
				if(!isset($objectCountMap[$tempName])){
					$objectCountMap[$tempName] = 0;
				}
				$objectCountMap[$tempName] = max($objectCountMap[$tempName], $tempCount);
			}
		}unset($node_ref);
		// Now we auto-generate the incomplete object names.
		foreach($copy->Exports as &$node_ref){
			if(isset($node_ref->ObjectName) && preg_match("/^(.*?)_[#|%]AUTO$/", $node_ref->ObjectName, $matches)){
				$tempName = $matches[1];
				if(!isset($objectCountMap[$tempName])){
					$objectCountMap[$tempName] = 1; // start with 1 here just to be safe
				}
				++$objectCountMap[$tempName];
				$node_ref->ObjectName = sprintf("%s_%d", $tempName, $objectCountMap[$tempName]);
			}
		}unset($node_ref);
	}
	
	$manualIndexToInt = [];
	$indexToString = [];
	//$indexToString[0] = 0; // fix
	foreach($copy->Exports as $i => &$node_ref){
		// Generate default index string.
		$indexToString[$i + 1] = sprintf("%s%s%d%s%s",
										$g_uassetCompressionSettings["defaultIndexPrefix"],
										$g_uassetCompressionSettings["defaultIndexSeparator"],
										$i + 1,
										$g_uassetCompressionSettings["defaultIndexSeparator"],
										($node_ref->ObjectName ?? "NonameObject"));
		if(isset($node_ref->{'$index'}) && !is_int($node_ref->{'$index'}) && !ctype_digit($node_ref->{'$index'})){
			// We found a non-integer custom index string.
			$indexToString[$i + 1] = $node_ref->{'$index'}; // overwrite default, use given.
			if(preg_match("/^" . $g_uassetCompressionSettings["defaultIndexPrefix"] . "\d+/", $node_ref->{'$index'})){
				// It's actually a default index string created previously.
				//printf("DEFAULT %s\n", $node_ref->{'$index'});
			}else{
				// Actual manual index. I don't bother checking the prefix.
				$manualIndexToInt[$node_ref->{'$index'}] = $i + 1;
				//printf("MANUAL  %s\n", $node_ref->{'$index'});
			}
		}
	}
	//printf("%s\n", json_encode($manualIndexToInt, JSON_PRETTY_PRINT)); exit(1);
	//printf("%s\n", json_encode($indexToString, JSON_PRETTY_PRINT)); exit(1);
	
	if($g_uassetCompressionSettings["addObjectNamesToNameMap"]){
		// Scan NameMap, see if any object names are missing.
		// This currently can't catch other missing NameMap values, mostly field names.
		$newlyAddedNames = [];
		foreach([ $copy->Imports, $copy->Exports] as $jj => $arr){
			$isImport = ($jj == 0);
			if($isImport){ continue; } // issues with this
			foreach($arr as &$node_ref){
				if(isset($node_ref->ObjectName)){
					$tempName = $node_ref->ObjectName;
					if(preg_match("/^(.*?)_(\d+)$/", $node_ref->ObjectName, $matches)){// && !$isImport){
						$tempName = $matches[1];
					}
					if(!in_array($tempName, $copy->NameMap)){
						$copy->NameMap[] = $tempName;
						$newlyAddedNames[] = $tempName;
						//printf("[WARNING] %s: |%s| missing from NameMap (%s)\n", __FUNCTION__, $tempName, ($isImport ? "Import" : "Export"));
					}
				}
			}unset($node_ref);
		}
		if(!empty($newlyAddedNames) && $g_uassetCompressionSettings["verbose"]){
			printf("Added to NameMap: [%s]\n", implode(",", array_map(function($x){ return ('"' . $x . '"'); }, $newlyAddedNames)));
		}
	}
	
	// Compress the json in all sorts of tricky ways.
	CompressUassetValueNode($copy);
	$text = CreateJson($copy, $g_uassetCompressionSettings);
	$text = preg_replace_callback("/\"SOLVES_STR_BEGIN(.*?)SOLVES_STR_END\"/", function($matches) { return (str_replace("|", "\"", $matches[1])); }, $text);
	$text = preg_replace_callback("/\"SCALAR_STR_BEGIN(.*?)SCALAR_STR_END\"/", function($matches) { return (str_replace("|", "\"", $matches[1])); }, $text);
	$text = preg_replace_callback("/\"META_STR_BEGIN(.*?)META_STR_END\"/",     function($matches) { return (str_replace("|", "\"", $matches[1])); }, $text);
	$text = strtr($text, [
		"\t\"Value\": false" => "\t\"Value\": 0",
		"\t\"Value\": true"  => "\t\"Value\": 1",
		"\"Value\":false"    => "\"Value\":0",
		"\"Value\":true"     => "\"Value\":1",
		"\r\n"               => "\n",
		]);
	
	if($g_uassetCompressionSettings["buildDefaultIndices"]){
		if($g_uassetCompressionSettings["verbose"]){ printf("Building default indices...\n"); }
		$lines = explode("\n", $text);
		$foundExports = false;
		foreach($lines as $lineIndex => &$line_ref){
			if(!$foundExports && !preg_match('/"Exports"/', $line_ref)){
				continue;
			}
			$foundExports = true;
			$lineCopy = unserialize(serialize($line_ref));
			
			// "$index": 10,
			static $simpleFields = [
				"\\\$index",
				"ClassDefaultObject",
				"ClassIndex",
				"ClassWithin",
				"LevelScriptActor",
				"Model",
				"OuterIndex",
				"Owner",
				"SuperIndex",
				"SuperStruct",
				"TemplateIndex",
			];
			foreach($simpleFields as $simpleField){
				$line_ref = preg_replace_callback('/^(\s*"' . $simpleField . '"\s*:\s*)(\d+)(.*)$/', function($matches) use($indexToString, $lineIndex) {
					list($original, $prior, $oldIntIndex, $post) = $matches;
					if($oldIntIndex > 0){
						if(isset($indexToString[$oldIntIndex])){
							return sprintf("%s\"%s\"%s", $prior, $indexToString[$oldIntIndex], $post);
						}else{
							printf(OverlayAssetMessage(sprintf("[WARNING] Referenced export %s doesn't exist (line %d)\n", $oldIntIndex, $lineIndex + 1), 255, 128, 128));
							return $original;
						}
					}
					return $original;
				}, $line_ref);
			}
			
			// "SerializationBeforeSerializationDependencies": [17,12,18,11],
			static $arrayFields = [
				"NotActors",
				"Actors",
				"Children",
				"CreateBeforeCreateDependencies",
				"CreateBeforeSerializationDependencies",
				"SerializationBeforeCreateDependencies",
				"SerializationBeforeSerializationDependencies",
			];
			foreach($arrayFields as $arrayField){
				$line_ref = preg_replace_callback('/^(\s*"' . $arrayField . '"\s*:\s*)(\[.*?\])(.*)$/', function($matches) use($indexToString, $lineIndex) {
					list($original, $prior, $stuff, $post) = $matches;
					//$arr = json_decode("[" . $stuff . "]");
					$arr = json_decode($stuff);
					foreach($arr as &$elem_ref){
						if(is_numeric($elem_ref) && $elem_ref > 0){
							if(isset($indexToString[$elem_ref])){
								//$elem_ref = '"' . $indexToString[$elem_ref] . '"';
								$elem_ref = $indexToString[$elem_ref];
							}else{
								printf(OverlayAssetMessage(sprintf("[WARNING] Referenced export %s doesn't exist (line %d)\n", $elem_ref, $lineIndex + 1), 255, 128, 128));
								return $original;
							}
						}
					}unset($elem_ref);
					//return sprintf("%s%s%s", $prior, implode(",", $arr), $post);
					return sprintf("%s%s%s", $prior, json_encode($arr, JSON_UNESCAPED_SLASHES), $post);
				}, $line_ref);
			}
			
			// "FuncMap": [["ExecuteUbergraph_BP_RacingBalls",1],["BP_OnSpawned",2],["BP_OnClientInitialized",3],["BPI_NewlyCompleteMilestone",4]],
			$line_ref = preg_replace_callback('/^(\s*"FuncMap"\s*:\s*)(\[\s*\[.*?\]\s*\])(.*)$/', function($matches) use($indexToString, $lineIndex) {
				list($original, $prior, $stuff, $post) = $matches;
				//printf("\n%s\n\n", $stuff);
				$arr = json_decode($stuff);
				foreach($arr as &$elem_ref){
					$funcName = $elem_ref[0];
					$arbitraryIndex_ref = &$elem_ref[1];
					if(is_numeric($arbitraryIndex_ref) && $arbitraryIndex_ref > 0){
						if(isset($indexToString[$arbitraryIndex_ref])){
							$arbitraryIndex_ref = $indexToString[$arbitraryIndex_ref];
						}else{
							printf(OverlayAssetMessage(sprintf("[WARNING] Referenced export %s doesn't exist (line %d)\n", $arbitraryIndex_ref, $lineIndex + 1), 255, 128, 128));
							return $original;
						}
					}
					unset($arbitraryIndex_ref);
				}unset($elem_ref);
				//
				//foreach($arr as &$elem_ref){
				//	if(is_numeric($elem_ref) && $elem_ref > 0){
				//		//$elem_ref = '"' . $indexToString[$elem_ref] . '"';
				//		$elem_ref = $indexToString[$elem_ref];
				//	}
				//}unset($elem_ref);
				////return sprintf("%s%s%s", $prior, implode(",", $arr), $post);
				return sprintf("%s%s%s", $prior, json_encode($arr, JSON_UNESCAPED_SLASHES), $post);
			}, $line_ref);
			
			// { "$type":"UAssetAPI.PropertyTypes.Objects.ObjectPropertyData, UAssetAPI", "Name":"DefaultSceneRootNode", "Value":16 }
			$objSearch = '/^(\s*{\s*"\$type"\s*:\s*"UAssetAPI.PropertyTypes.Objects.ObjectPropertyData, UAssetAPI".*?"Value"\s*:\s*)([\w\-"]*)(.*)$/';
			$line_ref = preg_replace_callback($objSearch, function($matches) use($indexToString, $lineIndex) {
				list($original, $prior, $oldArbitraryValue, $post) = $matches;
				//printf("%s |%s|\n", $original, $oldArbitraryValue);
				if(is_numeric($oldArbitraryValue) && $oldArbitraryValue > 0){
					if(isset($indexToString[$oldArbitraryValue])){
						return sprintf("%s\"%s\"%s", $prior, $indexToString[$oldArbitraryValue], $post);
					}else{
						printf(OverlayAssetMessage(sprintf("[WARNING] Referenced export %s doesn't exist (line %d)\n", $oldArbitraryValue, $lineIndex + 1), 255, 128, 128));
						return $original;
					}
				}
				return $original;
			}, $line_ref);
			
			$delegateSearch = '/^(\s*{\s*"\$type"\s*:\s*"UAssetAPI.PropertyTypes.Objects.FDelegate, UAssetAPI".*?"Object"\s*:\s*)([\w\-"]*)(.*)$/';
			$line_ref = preg_replace_callback($delegateSearch, function($matches) use($indexToString, $lineIndex) {
				list($original, $prior, $oldArbitraryValue, $post) = $matches;
				//printf("%s |%s|\n", $original, $oldArbitraryValue);
				if(is_numeric($oldArbitraryValue) && $oldArbitraryValue > 0){
					if(isset($indexToString[$oldArbitraryValue])){
						return sprintf("%s\"%s\"%s", $prior, $indexToString[$oldArbitraryValue], $post);
					}else{
						printf(OverlayAssetMessage(sprintf("[WARNING] Referenced export %s doesn't exist (line %d)\n", $oldArbitraryValue, $lineIndex + 1), 255, 128, 128));
						return $original;
					}
				}
				return $original;
			}, $line_ref);
			
			//if($lineCopy != $line_ref){ printf("[%d]%s\n[%d]%s\n\n", $lineIndex + 1, $lineCopy, $lineIndex + 1, $line_ref); } // debug
			
		}unset($line_ref);
		$text = implode("\n", $lines);
	}
	
	$doBakeManualIndices  = ($g_uassetCompressionSettings["bakeAllIndices"] || $g_uassetCompressionSettings["bakeManualIndices"]);
	$doBakeDefaultIndices = ($g_uassetCompressionSettings["bakeAllIndices"] || $g_uassetCompressionSettings["bakeDefaultIndices"]);
	
	$prefixList = [];
	if($doBakeManualIndices){
		$prefixList[] = $g_uassetCompressionSettings["manualIndexPrefix"];
	}
	if($doBakeDefaultIndices){
		$prefixList[] = $g_uassetCompressionSettings["defaultIndexPrefix"];
	}
	//$bakerChoices = implode("|", $prefixList);
	if(!empty($prefixList)){
		//printf("%s\n", $bakerChoices);
		$stringToIndex = array_flip($indexToString);
		$text = preg_replace_callback('/(?<!\\\\)("((?:' . implode('|', $prefixList) . ').*?)")/', function($matches) use($stringToIndex) {
			//var_dump($matches); exit(0);
			list($original, $defsiQuoted, $defsi) = $matches;
			if(!isset($stringToIndex[$defsi])){
				printf("[ERROR] Encountered unknown index string %s\n", $original);
				exit(1);
			}
			return $stringToIndex[$defsi];
		}, $text);
	}
	//$text = preg_replace_callback("/\"SOLVES_STR_BEGIN(.*?)SOLVES_STR_END\"/", function($matches) { return (str_replace("|", "\"", $matches[1])); }, $text);
	/*
	$doBakeManualIndices  = ($g_uassetCompressionSettings["bakeAllIndices"] || $g_uassetCompressionSettings["bakeManualIndices"]);
	$doBakeDefaultIndices = ($g_uassetCompressionSettings["bakeAllIndices"] || $g_uassetCompressionSettings["bakeDefaultIndices"]);
	if($doBakeManualIndices){
		// Bake in manually created indices as integer values back from strings.
		printf("Baking %d manual indices...\n",  count($manualIndexToInt));
		foreach($manualIndexToInt as $customIndex => $realIndex){
			// Since the format of manual indices is not defined we'll just have to iterate over all the manual ones.
			$text = str_replace("\"" . $customIndex . "\"", $realIndex, $text);
		}
	}
	if($doBakeDefaultIndices){
		// Bake in default-built indices as integer values back from strings.
		printf("Baking %d default indices...\n", count($indexToString) - count($manualIndexToInt));
		//foreach($indexToString as $realIndex => $defsi){
		//	//printf("Baking \"%s\" -> %d\n", $defsi, $realIndex);
		//	$text = str_replace("\"" . $defsi . "\"", $realIndex, $text);
		//}
		$stringToIndex = array_flip($indexToString);
		$search = '/"(' . $g_uassetCompressionSettings["defaultIndexPrefix"] . '\d+' . $g_uassetCompressionSettings["defaultIndexSeparator"] . '.*?)"/';
		$text = preg_replace_callback($search, function($matches) use($stringToIndex) {
			list($original, $defsi) = $matches;
			return $stringToIndex[$defsi];
		}, $text);
	}
	*/
	if(empty($text)){
		printf("[ERROR] Fatal error: the output is corrupted; cancelling changes.\n");
		printf("Error status reported by pcre: %s\n", preg_last_error_msg());
		exit(1);
	}
	
	OverlayAssetWrite($path, $text, $g_uassetCompressionSettings["verbose"]);
}

