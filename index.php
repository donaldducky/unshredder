<?php

$img = unshred('images/TokyoPanoramaShredded.png');
//$img = unshred('images/shredded_galaxy.png');
//$img = unshred('images/shredder_flower.png');

header('Content-Type: image/png');
imagepng($img);
imagedestroy($img);


function unshred($filename) {
  $img = imagecreatefrompng($filename);
  if (!$img) die('Unable to read image');

  $slice_order = analyze_slices($img);

  return reorder_slices($img, $slice_order);
}


// get gray value of an rgb image
function gray_at($src, $x, $y) {
  $rgb = imagecolorat($src, $x, $y);
  $r = ($rgb >> 16) & 0xFF;
  $g = ($rgb >> 8) & 0xFF;
  $b = $rgb & 0xFF;
  return round($r*.3 + $g*.59 + $b*.11);
}


function reorder_slices($src, $slice_order, $slice_width = 32) {
  $width = imagesx($src);
  $height = imagesy($src);
  $max_slice = round($width / $slice_width);

  $num_slices = count($slice_order);

  $dest = imagecreatetruecolor($num_slices * $slice_width, $height);
  $count = 0;
  foreach ($slice_order as $slice) {
    if ($slice >= $max_slice) continue;
    $dest_x = $count * $slice_width;
    $dest_y = 0;
    $src_x = $slice * $slice_width;
    $src_y = 0;
    imagecopy($dest, $src, $dest_x, $dest_y, $src_x, $src_y, $slice_width, $height);
    $count++;
  }

  return $dest;
}

function analyze_slices($src, $slice_width = 32) {
  $width = imagesx($src);
  $height = imagesy($src);

  $num_slices = $width / $slice_width;
  $profile = array();
  for ($i = 0; $i < $num_slices; $i++) {
    $profile[$i] = array(
      'left' => array(),
      'right' => array(),
    );
    $left_x = $i * $slice_width;
    $right_x = ($i+1)*$slice_width-1;

    foreach (array('left' => $left_x, 'right' => $right_x) as $index => $x) {
      for ($y = 0; $y < $height; $y++) {
        $profile[$i][$index][$y] = gray_at($src, $x, $y);
      }
    }
  }

  $data = array();
  for ($i = 0; $i < $num_slices; $i++) {
    for ($j = 0; $j < $num_slices; $j++) {
      if ($i >= $j) continue;
      $comparison = compare_slice($profile, $i, $j, $height, 'closest_match');
      $data[$i]['left'][$j] = $comparison['left'];
      $data[$i]['right'][$j] = $comparison['right'];
      $data[$j]['left'][$i] = $comparison['right'];
      $data[$j]['right'][$i] = $comparison['left'];
    }
  }
  for ($i = 0; $i < $num_slices; $i++) {
    for ($j = 0; $j < $num_slices; $j++) {
      if (isset($data[$i]['left'][$j])) {
        $left = $data[$i]['left'][$j];
        $right = $data[$i]['right'][$j];
      }
    }
  }

  $slice_data = array();
  // find sides of each piece
  for ($j = 0; $j < $num_slices; $j++) {
    $check = $j;
    $findings = array(
      'left' => null,
      'left_value' => null,
      'right' => null,
      'right_value' => null,
    );
    for ($i = 0; $i < $num_slices; $i++) {
      if ($i != $check) {
        if ($findings['left'] === null || $findings['left_value'] > $data[$check]['left'][$i]) {
          $findings['left'] = $i;
          $findings['left_value'] = $data[$check]['left'][$i];
        }
        if ($findings['right'] === null || $findings['right_value'] > $data[$check]['right'][$i]) {
          $findings['right'] = $i;
          $findings['right_value'] = $data[$check]['right'][$i];
        }
      }
    }
    $slice_data[$j] = $findings;
  }

  // try to find sequences
  // might as well start at the beginning
  $ok = array();
  for ($i = 0; $i < $num_slices; $i++) {
    $sequence = stitch($slice_data, $i);
    if (count(explode(':', $sequence)) == $num_slices) {
      if (isset($ok[$sequence])) {
        $ok[$sequence]++;
      } else {
        $ok[$sequence] = 1;
      }
    }
  }
  if (count($ok) == 0) return array();

  asort($ok);
  $best_sequence = array_pop(array_flip($ok));

  return explode(':', $best_sequence);
}


function stitch($slice_data, $start) {
  $num_slices = count($slice_data);
  $have = array();
  for ($i = 0; $i < $num_slices; $i++) {
    $have[$i] = true;
  }
  $sequence = '';
  $left = $slice_data[$start]['left'];
  $right = $slice_data[$start]['right'];
  // sanity check
  if ($left != $right) {
    unset($have[$start]);
    $sequence = "$left:$start:$right";
  }
  // try moving right
  $current_slice = $slice_data[$start]['right'];
  while ($current_slice !== false) {
    unset($have[$current_slice]); // we looked at this slice
    $right = $slice_data[$current_slice]['right'];
    if (isset($have[$right])) {
      $current_slice = $right;
      $sequence .= ':' . $current_slice;
    } else {
      $current_slice = false;
    }
  }
  // try going left
  $current_slice = $slice_data[$start]['left'];
  while ($current_slice) {
    unset($have[$current_slice]); // we looked at this slice
    $left = $slice_data[$current_slice]['left'];
    if (isset($have[$left])) {
      $current_slice = $left;
      $sequence = $current_slice . ':' . $sequence;
    } else {
      $current_slice = false;
    }
  }

  return $sequence;
}



function compare_slice($profile, $slice1, $slice2, $height, $score_function) {
  $p1 = $profile[$slice1];
  $p2 = $profile[$slice2];

  $sum1 = 0;
  $sum2 = 0;
  for ($y = 0; $y < $height; $y++) {
    // compare p1 left to p2 right
    $a1 = $p1['left'][$y];
    $b1 = $p2['right'][$y];
    $c1 = abs($a1 - $b1);
    $c1 = $score_function($p1['left'], $p2['right'], $y);
    $sum1 += $c1;
    // compare p1 right to p2 left
    $a2 = $p1['right'][$y];
    $b2 = $p2['left'][$y];
    $c2 = abs($a2 - $b2);
    $c2 = $score_function($p1['right'], $p2['left'], $y);
    $sum2 += $c2;
  }

  return array('left' => $sum1, 'right' => $sum2);
}

function closest_match($p1, $p2, $y) {
  // check each pixel with the one above and below as well on the next image
  $check = array();
  if (isset($p1[$y-1])) $check[] = $y-1;
  $check[] = $y;
  if (isset($p1[$y+1])) $check[] = $y+1;

  $a = array();

  // compare pixels
  //    / b        a \
  //  a - b   and  a - b
  //    \ b        a /
  foreach ($check as $index) {
    $a[] = abs($p1[$y] - $p2[$index]);
    $a[] = abs($p2[$y] - $p1[$index]);
  }

  return min($a);
}

