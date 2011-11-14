<?php

//$img = unshred('images/TokyoPanoramaShredded.png');
//$img = unshred('images/shredded_galaxy.png');
//$img = unshred('images/shredder_flower.png');
$img = unshred('images/shredded_flower_10.png');

header('Content-Type: image/png');
imagepng($img);
imagedestroy($img);


function unshred($filename) {
  $img = imagecreatefrompng($filename);
  if (!$img) die('Unable to read image');

  $slice_width = find_slice_width($img);

  $slice_order = analyze_slices($img, $slice_width);
  return reorder_slices($img, $slice_order, $slice_width);
}


// convert the rgb color at an image to a gray value
function gray_at($src, $x, $y) {
  $rgb = imagecolorat($src, $x, $y);
  $r = ($rgb >> 16) & 0xFF;
  $g = ($rgb >> 8) & 0xFF;
  $b = $rgb & 0xFF;
  return round($r*.3 + $g*.59 + $b*.11); // http://en.wikipedia.org/wiki/Grayscale#Converting_color_to_grayscale
}

// generate an image given the order of the slices
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
      if ($i < $j) {
        $comparison = compare_slice($profile, $i, $j, $height, 'closest_match');
        $data[$i]['left'][$j] = $comparison['left'];
        $data[$i]['right'][$j] = $comparison['right'];
        $data[$j]['left'][$i] = $comparison['right'];
        $data[$j]['right'][$i] = $comparison['left'];
      }
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

  // find 'best' left and right slices for each slice
  $slice_data = array();
  for ($i = 0; $i < $num_slices; $i++) {
    $slice_data[$i]['left'] = find_min_slice($data[$i]['left']);
    $slice_data[$i]['right'] = find_min_slice($data[$i]['right']);
  }

  // try to find sequences, count how many times each sequence occurs and return the 'best'
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

function find_min_slice($slices) {
  $min_value = min($slices);
  foreach ($slices as $key => $value) {
    if ($value == $min_value) return $key;
  }
  die('how could this happen?');
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

  $directions = array('right', 'left');
  foreach ($directions as $direction) {
    $current_slice = $slice_data[$start][$direction];
    while ($current_slice !== false) { // need strict equals because 0 == false
      unset($have[$current_slice]); // we looked at this slice
      $next_slice = $slice_data[$current_slice][$direction];
      if (isset($have[$next_slice])) {
        if ($direction == 'right') {
          $sequence .= ':' . $next_slice;
        } else {
          $sequence = $next_slice . ':' . $sequence;
        }
        $current_slice = $next_slice;
      } else {
        $current_slice = false;
      }
    }
  }

  return $sequence;
}


// calculate score for each slice, lower = better match
function compare_slice($profile, $slice1, $slice2, $height, $score_function) {
  $compare = array(
    'left' => 0,
    'right' => 0,
  );

  for ($y = 0; $y < $height; $y++) {
    foreach ($compare as $side => $score) {
      $slice1_side = $side;
      $slice2_side = ($slice1_side == 'left') ? 'right' : 'left';
      $compare[$side] += $score_function($profile[$slice1][$slice1_side], $profile[$slice2][$slice2_side], $y);
    }
  }

  return $compare;
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

function find_slice_width($src) {
  $ts_start = microtime(true);

  $width = imagesx($src);
  $divisors = get_divisors($width);

  $avg_diff = array();
  foreach ($divisors as $divisor) {
    $avg_diff[$divisor] = score_slice_at_width($src, $divisor);
  }

  $total_sum = 0;
  $total_data_points = 0;
  foreach ($avg_diff as $divisor => $data) {
    $total_sum += $data['sum'];
    $total_data_points += $data['data_points'];
  }
  $weighted_average = $total_sum / $total_data_points;
  $max_weight = 0;
  $best_divisor = 0;
  foreach ($avg_diff as $divisor => $data) {
    $sum2 = $weighted_average * $data['data_points'];
    $diff = abs($data['sum'] - $sum2);
    $weight = $diff*$diff / $data['data_points'];
    if ($weight > $max_weight) {
      $max_weight = $weight;
      $best_divisor = $divisor;
    }
  }

  return $best_divisor;
}

function get_divisors($n) {
  $max = round(sqrt($n));

  $divisors = array();
  for ($i = 1; $i <= $max; $i++) {
    // this is the ghetto way of doing it
    if ($n % $i == 0) {
      $divisors[] = $i;
      $divisors[] = $n / $i;
    }
  }
  sort($divisors);

  return $divisors;
}

function score_slice_at_width($src, $slice_width) {
  $width = imagesx($src);
  $height = imagesy($src);

  if ($slice_width == $width) return array(
    'sum' => 0,
    'data_points' => 1,
    'avg' => 0,
  );

  $num_slices = $width / $slice_width;
  $sum = 0;
  $data_points = 0;
  // TODO: optimize this algorithm...it's O(n^2), gross
  for ($i = 0; $i < $num_slices; $i++) {
    // compare the pixels where the slices meet
    $right_x = ($i + 1) * $slice_width;
    $left_x = $right_x - 1;
    if ($right_x < $width) {
      $y_sum = 0;
      for ($y = 0; $y < $height; $y++) {
        // use same algorithm as above
        $check = array();
        if ($y > 0) $check[] = $y - 1;
        $check[] = $y;
        if ($y + 1 < $height) $check[] = $y + 1;
        $diffs = array();
        foreach ($check as $index) {
          $diffs[] = abs(gray_at($src, $left_x, $y) - gray_at($src, $right_x, $index));
        }
        $y_sum += min($diffs);
      }
      $sum += $y_sum / $height;
      $data_points++;
    }
  }

  return array(
    'sum' => $sum,
    'data_points' => $data_points,
    'avg' => $sum / $data_points,
  );
}

