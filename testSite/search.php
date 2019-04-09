<!DOCTYPE html>
<html>
<head>
  <style>
    #submit {
      padding-left: 5px;
      padding-right:5px;
    }
  </style>
  <?php
    session_start();
    require_once ('FileMaker.php');
    require_once ('partials/header.php');
    require_once ('functions.php');

    // echo "FM_FILE: $FM_FILE <br>
    //       FM_HOST: $FM_HOST <br>
    //       FM_USER: $FM_USER <br>
    //       FM_PASS: $FM_PASS <br>";

    $layouts = $fm->listLayouts();

    if (FileMaker::isError($layouts)) {
      $_SESSION['error'] = $layouts->getMessage();
      header('Location: error.php');
      exit;
    }

    $layout = $layouts[0];

    foreach ($layouts as $l) {
      //get current database name
      $page = substr($_SERVER['REQUEST_URI'], strrpos($_SERVER['REQUEST_URI'], '=') + 1);
      if ($page == 'mi') {
        if (strpos($l, 'search') !== false) {
          $layout = $l;
          break;
        }
      }
      else if (strpos($l, 'search') !== false) {
        $layout = $l;
      }
    }
    $fmLayout = $fm->getLayout($layout);
    $layoutFields = $fmLayout->listFields();
  ?>
</head>
<body class="container-fluid">
  <?php require_once ('partials/navbar.php');?>
  <div class="row">
    <div class="col">
        <h1><b><?php echo ucfirst($_GET['Database']); ?> Search</b></h1>
    </div>
  </div>
  <form action="render.php" method="get" id = "submit-form">
    <div class ="row">
      <div id="form" class = "col-sm-6">
        <div class="form-group">
          <input type="text" name="Database" style="display:none;" 
          value=<?php if (isset($_GET['Database'])) echo htmlspecialchars($_GET['Database']); ?>>
        </div>
        <div class="row">
          <div id = 'submit'>
            <input id="form" class="btn btn-primary" type="button" value="Submit"  style = "font-size:12px;" onclick="Process(clearURL())">    
          </div>     
        </div>
        <br>   
        <?php 
          $count = 0;
          foreach ($layoutFields as $rf) {
            //echo $rf;
            $ignoreValues = ['SortNum', 'AccessionNumerical', 'Imaged', 'IIFRNo', 'Photographs::photoFileName', 'Event::eventDate'];
            if (in_array($rf, $ignoreValues)) continue; 
            if($count%2==0) {?>
          <div class="row">
            <?php }?>
            <!--- Section that is one label and one search box --->
            <div class="col-sm-3">
              <label for="field-<?php echo $rf?>">
                <?php echo htmlspecialchars(formatField($rf)) ?>
              </label>
            </div>
            <div class="col-sm-3">   
              <input type="text" id="field-<?php echo $rf?>" 
              <?php
                if (isset($_POST[str_replace(' ', '_', $rf)]))
                  echo "value=".htmlspecialchars($_POST[str_replace(' ', '_', $rf)]);
              ?> 
              name="<?php echo htmlspecialchars($rf) ?>"
              class="form-control">
            </div>
            <!--- End of a single label, input instance --->
            <?php if($count%2==1) {?>
          </div>
            <?php }?>
        <?php $count++; } 
          if($count%2==1) {
            echo '</div>';
          }
        ?>
      </div>
      <div id="legend" class="border col-sm-5"> 
        <?php
        if($_GET['Database'] === 'entomology'){
          echo '<div class="row">';
            echo '<div class="col-sm-12">';
              echo '<a href="https://www.zoology.ubc.ca/entomology/"><img width="100%" src="images/entomology-link-image.jpg"></a>';
            echo '</div>';
          echo '</div>';
        }
        ?>
        <div class="row">
          <div class="col" style="text-align:center;">
            <h2 style="padding-bottom:12px"> Search Options </h2>
          </div>
        </div>
        <div class="row">
          <div class="col">
            <h3 style="padding-bottom:12px"> Search Operators </h3>
          </div>
        </div>
        <div class="row">
          <div class="col-sm-1"> == </div>
          <div class="col-sm-11"> match entire field exactly </div>
        </div>
        <div class="row">
          <div class="col-sm-1"> &lt </div>
          <div class="col-sm-11"> find records with values less than to the one specified </div>
        </div>
        <div class="row">
          <div class="col-sm-1"> &lt= </div>
          <div class="col-sm-11">  find records with values less than or equal to the one specified </div>
        </div>
        <div class="row">
          <div class="col-sm-1"> &gt </div>
          <div class="col-sm-11">  find records with values greater than to the one specified </div>
        </div>
        <div class="row">
          <div class="col-sm-1"> &gt= </div>
          <div class="col-sm-11">  find records with values greater than or equal to the one specified </div>
        </div>
        <div class="row">
          <div class="col-sm-1"> ... </div>
          <div class="col-sm-11">  find records with values in a range (Ex. 10...20) </div>
        </div>
        <div class="row">
          <div class="col-sm-1"> * </div>
          <div class="col-sm-11">  match zero or more characters </div>
        </div>
        <div class="row">
          <div class="col-sm-1"> \ </div>
          <div class="col-sm-11">  escape any character </div>
        </div>
        <div class = "row">
          <div class = "col"> 
            <h4 style=padding-top:12px;>Search By</h4>
          </div>
        </div>
        <div class = "row">
          <div class="col">
            <div class = "btn-group btn-group-toggle" data-toggle="buttons" >
              <label class = "btn btn-primary active" style="font-size:12px;">
                <input type="radio"  id = "and" autocomplete="off"  checked> AND 
              </label>
              <label class = "btn btn-primary" style="font-size:12px;">
                <input type="radio" id = "or" autocomplete="off" > <span style="visibility: hidden">&nbsp;</span>OR<span style="visibility: hidden">&nbsp;</span>
              </label> 
            </div>
          </div>
        </div>
        <div class="row" style="padding-top:12px;">
          <div class="col">
            <a href="render.php?Database=<?php echo htmlspecialchars($_GET['Database'])?>" 
                role="button" class="btn btn-primary" 
                style="font-size:12px; text-align:left; padding-left:2px; padding-right:2px;">Show All Records</a>   
          </div>
        </div>
        <div class="row" style="padding-top:12px;">
          <?php if ($_GET['Database'] == 'fish' || $_GET['Database'] == 'avian' ||$_GET['Database'] == 'herpetology' || $_GET['Database'] == 'mammal'
          || $_GET['Database'] == 'vwsp' || $_GET['Database'] == 'bryophytes' || $_GET['Database'] == 'entomology' ||
          $_GET['Database'] == 'fungi' || $_GET['Database'] == 'lichen' || $_GET['Database'] == 'algae') { ?>
            <div class="col">
              <input type="checkbox" value="" id="imageCheck">
              <label for="imageCheck">
                Only show records that contain an image
              </label>
            </div>
            <input type="hidden" name = "hasImage" id = "hasImage">
          <?php } ?>
          <input type="hidden" name = "type" id = "type">
        </div>
      </div>
      <div>
        <?php
        if ($_GET['Database'] == 'avian') {
          $getSampleScript = $fm->newPerformScriptCommand('examples', 'Search Page Sample Selection');
          $result = $getSampleScript->execute(); 
          //echo $result->getRecords()[0]->getField('catalogNumber');
        }
        ?>
      </div>
    </div>
  </form>
  <?php require_once("partials/footer.php");?>
  <script src="js/process.js"> </script>
</body>
</html>
