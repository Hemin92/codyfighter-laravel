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

            $target = $this->getRandomTarget($skill['possible_targets']);

            $this->cast($skill['id'], $target['x'], $target['y']);

            $this->castSkills();
            break;
        }
        
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
