<?php
/**
 * Implementation of Charts view
 *
 * @category   DMS
 * @package    SeedDMS
 * @license    GPL 2
 * @version    @version@
 * @author     Uwe Steinmann <uwe@steinmann.cx>
 * @copyright  Copyright (C) 2002-2005 Markus Westphal,
 *             2006-2008 Malcolm Cowe, 2010 Matteo Lucarelli,
 *             2010-2012 Uwe Steinmann
 * @version    Release: @package_version@
 */

/**
 * Include parent class
 */
require_once("class.Bootstrap.php");

/**
 * Class which outputs the html page for Charts view
 *
 * @category   DMS
 * @package    SeedDMS
 * @author     Markus Westphal, Malcolm Cowe, Uwe Steinmann <uwe@steinmann.cx>
 * @copyright  Copyright (C) 2002-2005 Markus Westphal,
 *             2006-2008 Malcolm Cowe, 2010 Matteo Lucarelli,
 *             2010-2012 Uwe Steinmann
 * @version    Release: @package_version@
 */
class SeedDMS_View_Charts extends SeedDMS_Bootstrap_Style {

	function js() { /* {{{ */
		$data = $this->params['data'];
		$type = $this->params['type'];

		header('Content-Type: application/javascript');

?>


	$("<div id='tooltip'></div>").css({
		position: "absolute",
		display: "none",
		padding: "5px",
		color: "white",
		"background-color": "#000",
		"border-radius": "5px",
		opacity: 0.80
	}).appendTo("body");

<?php
if(in_array($type, array('docspermonth'))) {
?>

	var data = [
<?php
	if($data) {
		foreach($data as $i=>$rec) {
			$key = mktime(12, 0, 0, substr($rec['key'], 5, 2), 1, substr($rec['key'], 0, 4)) * 1000;
			echo '["'.$rec['key'].'",'.$rec['total'].'],'."\n";
		}
	}
?>
	];
	
	$.plot("#chart", [data], {
		xaxis: {
			mode: "categories",
			tickLength: 0,
		},
		series: {
			bars: {
				show: true,
				align: "center",
				barWidth: 0.8,
			},
		},
		grid: {
			hoverable: true,
			clickable: true
		}
	});

	$("#chart").bind("plothover", function (event, pos, item) {
		if(item) {
			var x = item.datapoint[0];//.toFixed(2),
					y = item.datapoint[1];//.toFixed(2);
			$("#tooltip").html(item.series.xaxis.ticks[x].label + ": " + y)
				.css({top: pos.pageY-35, left: pos.pageX+5})
				.fadeIn(200);
		} else {
			$("#tooltip").hide();
		}
	});
	
	<?php
} elseif(in_array($type, array('dsl'))) {	
?>

$(document).ready(function(){ 
var mydatatable= $('#my_data').DataTable({
	
		 bJQueryUI: true,
		 
             "sPaginationType": "full_numbers",
			 "bSort": false,
			 "oLanguage": {"sZeroRecords": "", "sEmptyTable": ""},
			 "bFilter": false,
			 "bInfo": true
	 
});
<?php
	if($data) {
		foreach($data as $rec) {
			$docId=$rec['docid'];
			$name=$rec['docname'];
			$version=$rec['version'];
			$docpath=$rec['folderList'];
			$key=htmlspecialchars($rec['key']);			
			?>
			
var docId=<?php echo json_encode($docId); ?>;
var name=<?php echo json_encode($name); ?>;
var status=<?php echo json_encode($key); ?>;
var version=<?php echo json_encode($version); ?>;
var docpath=<?php echo json_encode($docpath); ?>;


mydatatable.row.add( [
        docpath,
        name,
        status,
        version
    ] ).draw();

		<?php
		}
	}
?>
mydatatable.order( [ 2, 'asc' ] )
.draw();
		}); 
 
 <?php
} elseif(in_array($type, array('dslRejected','dslDraft'))) {	
?>
$(document).ready(function(){ 
var mydatatable= $('#my_data').DataTable({
	
		 bJQueryUI: true,
		 
             "sPaginationType": "full_numbers",
			 "bSort": false,
			 "oLanguage": {"sZeroRecords": "", "sEmptyTable": ""},
			 "bFilter": false,
			 "bInfo": true
	 
});
<?php
	if($data) {
		foreach($data as $rec) {
			$docId=$rec['docid'];
			$name=$rec['docname'];
			$version=$rec['version'];
			$docpath=$rec['folderList'];
			$key=htmlspecialchars($rec['key']);			
			?>
			


var docId=<?php echo json_encode($docId); ?>;
var name=<?php echo json_encode($name); ?>;
var version=<?php echo json_encode($version); ?>;
var docpath=<?php echo json_encode($docpath); ?>;

mydatatable.row.add( [
        docpath,
        name,        
        version
    ] ).draw();

			<?php
		}
	}
?>

	
 });
 	
<?php
} elseif(in_array($type, array('docsaccumulated'))) {
?>
	var data = [
<?php
	if($data) {
		foreach($data as $rec) {
			echo '['.htmlspecialchars($rec['key']).','.$rec['total'].'],'."\n";
		}
	}
?>
	];
	var plot = $.plot("#chart", [data], {
		xaxis: { mode: "time" },
		series: {
			lines: {
				show: true
			},
			points: {
				show: true
			}
		},
		grid: {
			hoverable: true,
			clickable: true
		}
	});

	$("#chart").bind("plothover", function (event, pos, item) {
		if(item) {
			var x = item.datapoint[0];//.toFixed(2),
					y = item.datapoint[1];//.toFixed(2);
			$("#tooltip").html($.plot.formatDate(new Date(x), '%e. %b %Y') + ": " + y)
				.css({top: pos.pageY-35, left: pos.pageX+5})
				.fadeIn(200);
		} else {
			$("#tooltip").hide();
		}
	});
<?php
} else {
?>
	var data = [
<?php
	if($data) {
		foreach($data as $rec) {
			echo '{ label: "'.htmlspecialchars($rec['key']).'", data: [[1,'.$rec['total'].']]},'."\n";
		}
	}
?>
	];
$(document).ready( function() {
	
	$.plot('#chart', data, {
		series: {
			pie: { 
				show: true,
				radius: 1,
				label: {
					show: true,
					radius: 2/3,
					formatter: labelFormatter,
					threshold: 0.1,
					background: {
						opacity: 0.8
					}
				}
			}
		},
		grid: {
			hoverable: true,
			clickable: true
		},
		legend: {
			show: true,
			container: '#legend'
		}
	});

	$("#chart").bind("plothover", function (event, pos, item) {
		if(item) {
			var x = item.series.data[0][0];//.toFixed(2),
					y = item.series.data[0][1];//.toFixed(2);

			$("#tooltip").html(item.series.label + ": " + y + " (" + Math.round(item.series.percent) + "%)")
				.css({top: pos.pageY-35, left: pos.pageX+5})
				.fadeIn(200);
		} else {
			$("#tooltip").hide();
		}
	});
	function labelFormatter(label, series) {
		return "<div style='font-size:8pt; line-height: 14px; text-align:center; padding:2px; color:black; background: white; border-radius: 5px;'>" + label + "<br/>" + series.data[0][1] + " (" + Math.round(series.percent) + "%)</div>";
	}
});
<?php
}
	} /* }}} */

	function show() { /* {{{ */
		$this->dms = $this->params['dms'];
		$user = $this->params['user'];
		$rootfolder = $this->params['rootfolder'];
		$data = $this->params['data'];
		$type = $this->params['type'];
		

		$this->htmlAddHeader(
			'<script type="text/javascript" src="../styles/bootstrap/flot/jquery.flot.min.js"></script>'."\n".
			'<script type="text/javascript" src="../styles/bootstrap/flot/jquery.flot.pie.min.js"></script>'."\n".
			'<script type="text/javascript" src="../styles/bootstrap/flot/jquery.flot.categories.min.js"></script>'."\n".
			'<script type="text/javascript" src="../styles/bootstrap/flot/jquery.flot.time.min.js"></script>'."\n".
			'<script type="text/javascript" src="../styles/bootstrap/jquery/dataTables.bootstrap.min.js"></script>'."\n".
			'<script type="text/javascript" src="../styles/bootstrap/jquery/jquery.dataTables.min.js"></script>');
			
						
			$this->htmlAddHeader('<link href="../styles/'.$this->theme.'/jquery/jquery.dataTables.css" rel="stylesheet">'."\n", 'css');
			$this->htmlAddHeader('<link href="../styles/'.$this->theme.'/jquery/dataTables.bootstrap.css" rel="stylesheet">'."\n", 'css');
			//$this->htmlAddHeader('<link href="../styles/'.$this->theme.'/jquery/bootstrap.min.css" rel="stylesheet">'."\n", 'css');
			$this->htmlAddHeader('<link href="../styles/'.$this->theme.'/jquery/jquery.dataTables_themeroller.css" rel="stylesheet">'."\n", 'css');

		$this->htmlStartPage(getMLText("folders_and_documents_statistic"));
		$this->globalNavigation();
		$this->contentStart();
		$this->pageNavigation(getMLText("admin_tools"), "admin_tools");

		echo "<div class=\"row-fluid\">\n";

		echo "<div class=\"span3\">\n";
		$this->contentHeading(getMLText("chart_selection"));
		$this->contentContainerStart();
		//Innoright foreach(array('docsperuser', 'sizeperuser', 'docspermimetype', 'docspercategory', 'docsperstatus', //'docspermonth', 'docsaccumulated','dsl','dslRejected','dslDraft') as $atype) {
		//	echo "<div><a href=\"?type=".$atype."\">".getMLText('chart_'.$atype.'_title')."</a></div>\n";
		//}
		foreach(array('dsl','dslRejected','dslDraft') as $atype) {
			echo "<div><a href=\"?type=".$atype."\">".getMLText('chart_'.$atype.'_title')."</a></div>\n";
		}
		$this->contentContainerEnd();
		echo "</div>\n";

		if(in_array($type, array('docspermonth', 'docsaccumulated','dsl','dslRejected','dslDraft'))) {
			echo "<div class=\"span9\">\n";
		} elseif((in_array($type, array('dsl')))){
			echo "<div>\n";
		} else {
			echo "<div class=\"span6\">\n";
		}
		$this->contentHeading(getMLText('chart_'.$type.'_title'));
		$this->contentContainerStart();
?>

<?php

if(in_array($type, array('dsl'))) {

echo "<div class=\"table-responsive\"><table id=\"my_data\" >  
                          <thead>
<tr> <th>Document Path</th>
  <th>Document Name</th>
  <th>Document Status</th>
  <th>Document Version</th>
 
  </tr>
                          </thead><tbody></tbody></table></div> ";
}elseif (in_array($type, array('dslRejected','dslDraft'))){
	echo "<div class=\"table-responsive\"><table id=\"my_data\" >  
                          <thead>
<tr> <th>Document Path</th>
  <th>Document Name</th>
  
  <th>Document Version</th>
 
  </tr>
                          </thead><tbody></tbody></table> </div>";
}else {
	
	echo " <div id=\"chart\" style=\"height: 400px;\" class=\"chart\"></div>\n";
}

		$this->contentContainerEnd();
		echo "</div>\n";

		if(!in_array($type, array('docspermonth', 'docsaccumulated','dsl','dslRejected','dslDraft'))) {
			echo "<div class=\"span3\">\n";
			$this->contentHeading(getMLText('legend'));
			$this->contentContainerStart('', 'legend');
			$this->contentContainerEnd();
			echo "</div>\n";
		}

		echo "</div>\n";

		$this->contentContainerEnd();
		$this->contentEnd();
		$this->htmlEndPage();
	} /* }}} */
}
?>
