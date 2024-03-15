<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class CodyBot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $game;
    public $strategy;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    public function initGame() {
        $ckey = env('CKEY_0');
        $this->game = Http::post("https://game.codyfight.com/?ckey={$ckey}&mode=0");
    }

    public function checkGame() {
        $ckey = env('CKEY_0');
        $this->game = Http::get("https://game.codyfight.com/?ckey={$ckey}");
    }

    public function cast($skillId, $x, $y) {
        $ckey = env('CKEY_0');
        $this->game = Http::patch("https://game.codyfight.com/?ckey={$ckey}&skill_id={$skillId}&x={$x}&y={$y}");
    }

    public function move($x, $y) {
        $ckey = env('CKEY_0');
        $this->game = Http::put("https://game.codyfight.com/?ckey={$ckey}&x={$x}&y={$y}");
    }

    public function waitForOpponent() {
        while ($this->game['state']['status'] == 0) {
            sleep(1);
            $this->checkGame();
        }
    }

    public function playGame() {
        while ($this->game['state']['status'] == 1) {
            if ($this->game['players']['bearer']['is_player_turn']) {
                $this->castSkills();
                $this->makeMove();
            } else {
              sleep(1);
              $this->checkGame();
            }
          }
    }

    public function getRandomTarget($targets) {
        $randomIndex = rand(0, count($targets) - 1);

        return $targets[$randomIndex];
    }

    public function getRandomMove(): array {
        $possibleMoves = array_filter($this->game['players']['bearer']['possible_moves'], function ($move) { return isset($move['type']) ? $move['type'] != 12 : true ;});
      

        return $possibleMoves[rand(0, count($possibleMoves) - 1)];     
    }

    public function makeMove() {
        $move = $this->getRandomMove();
        
        if ($this->game['players']['bearer']['is_player_turn']) {
            $this->move($move['x'], $move['y']);
        }
    }

    public function castSkills() {
        if ($this->game['players']['bearer']['is_player_turn'] === false) {
            return;
        }
        
        foreach ($this->game['players']['bearer']['skills'] as $skill) {
            $hasEnoughEnergy = $skill['cost'] <= $this->game['players']['bearer']['stats']['energy'];

            if ($skill['status'] !== 1 || count($skill['possible_targets']) ==0 || !$hasEnoughEnergy) 
                continue;

            $exitPos = $this->getClosestExit();
            $ryoPos = isset($this->findSpecialAgent(1)['position']) ? $this->findSpecialAgent(1)['position'] : null;
            $ripperPos = isset($this->findSpecialAgent(4)['position']) ? $this->findSpecialAgent(4)['position'] : null;
            $opponentPos = $this->game['players']['opponent']['position'];
            $pitHoles = $this->findPits();
            $possibleTargets = array_filter($skill['possible_targets'], function ($target) {
                if ($skill['id'] == 1) {
                    foreach ($pitHoles as $hole) {
                        if ($hole['x'] == $target['x'] && $hole['y'] == $target['y']) {
                            return true;
                        }
                    }
                }

                if ($skill['damage']) {
                    return $target['x'] == $opponentPos['x'] && $target['y'] == $opponentPos['y'];
                }

                foreach ($pitHoles as $hole) {
                    if ($hole['x'] == $target['x'] && $hole['y'] == $target['y']) {
                        return false;
                    }
                }
            });

            if (count($possibleTargets) == 0) continue;

            $bestTarget = null;
            switch ($this->strategy) {
                case "exit":
                    $bestTarget = $this->getTargetPosition($possibleTargets, $exitPos);
                    break;
                case "ryo":
                    $bestTarget = $this->getTargetPosition($possibleTargets, $ryoPos);
                    break;
                
                case "ripper":
                    $bestTarget = $this->getTargetPosition($possibleTargets, $ripperPos, false);    
                    break;

                case "hunter":
                    $bestTarget = $this->getTargetPosition($possibleTargets, $opponentPos, true);    
                    break;
                case "stay":
                    $bestTarget = null;   
                    break;
              
            }
        }
    }

    public function findPits() {
        $map = $this->game['map'];
        $y = 0;

        return array_reduce($map, function ($pits, $row) {
            foreach ($row as $x => $tile) {
                if ($tile['type'] == 12) {
                    $pits[] = ['x' => $x, 'y' => $y];
                }
            }
            $y ++;
            return $pits;
        }, array());    
    }
    public function findExits() {
        $map = $this->game['map'];
        $y = 0;
        return array_reduce($map, function ($exits, $row) {
            foreach ($row as $x => $tile) {
                if ($tile['type'] == 2) {
                    $exits[] = ['x' => $x, 'y' => $y];
                }
            }
            $y ++;
            return $exits;
        }, array());    
    }

    public function distance ($x1, $y1, $x2, $y2) {
        $a = $x1 - $x2;
        $b = $y1 - $y2;
    
        return sqrt ($a * $a + $b * $b);
    }

    public function getClosestExit()
    {
        $exits = $this->findExits();

        $distances = array();

        foreach ($exits as $exit) {
            $distance = $this->distance($this->game['players']['bearer']['position']['x'], $this->game['players']['bearer']['position']['y'], $exit['x'], $exit['y']);

            $distances[] = $distance;
        }
        
        $distances = usort($distances, function ($a, $b) {
            return $a['distance'] - $b['distance'];
        });

        return $distances[0]['exit'] ?? null;
    }

    public function findSpecialAgent ($type) {
        foreach ($this->game['special_agents'] as $agent) {
            if ($agent['type'] == $type) {
                return $agent;
            }
        }

        return null;
    }

    public function play(): void
    {
        $this->initGame();
        $this->waitForOpponent();
        $this->playGame();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        while (true) {
            try {
                $this->play();
                sleep(60);
            } catch (Exception $e) {
                
            }
        }
    }
}
