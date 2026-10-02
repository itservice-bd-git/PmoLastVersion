<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\Project;

class DashboardController extends Controller
{
    public function index()
    {
        $projects = Project::withAvg('cabinets', 'progress')
            ->withAvg('projectTasks', 'progress')
            ->withCount('cabinets')
            ->get();

        $activeProjects = $projects->whereNotIn('status', [Project::STATUS_COMPLETED, Project::STATUS_CANCELLED]);

        $nearDue = $activeProjects->filter(function (Project $project) {
            return $project->due_date
                && $project->due_date->greaterThanOrEqualTo(now())
                && now()->diffInDays($project->due_date) <= 7;
        });

        $delayed = $activeProjects->filter(fn (Project $project) => $project->risk_level === 'overdue');
        $atRisk = $activeProjects->filter(fn (Project $project) => $project->risk_level === 'at_risk');

        $totalCabinets = Cabinet::count();
        $completedCabinets = Cabinet::where('status', Cabinet::STATUS_COMPLETED)->count();
        $inProductionCabinets = Cabinet::where('status', Cabinet::STATUS_IN_PROGRESS)->count();
        $avgProduction = (int) round(Cabinet::avg('progress') ?? 0);

        $recentProjects = $projects->sortByDesc('created_at')->take(10);

        return view('dashboard', [
            'activeProjectsCount' => $activeProjects->count(),
            'nearDueCount' => $nearDue->count(),
            'delayedCount' => $delayed->count(),
            'atRiskCount' => $atRisk->count(),
            'totalCabinets' => $totalCabinets,
            'completedCabinets' => $completedCabinets,
            'inProductionCabinets' => $inProductionCabinets,
            'avgProduction' => $avgProduction,
            'recentProjects' => $recentProjects,
        ]);
    }
}
