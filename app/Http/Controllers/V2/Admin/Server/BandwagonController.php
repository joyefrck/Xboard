<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\BandwagonVps;
use App\Services\Bandwagon\{BandwagonException, CsvImporter, InventoryService, VpsData};
use Illuminate\Http\Request;

class BandwagonController extends Controller
{
    public function __construct(protected InventoryService $service, protected CsvImporter $importer) {}

    private function respond(callable $action)
    {
        try { $response = $this->success($action()); }
        catch (BandwagonException $e) { $response = $this->fail([422, $e->getMessage()]); }
        return $response->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    public function fetch(Request $request)
    {
        $data = $request->validate(['search' => 'nullable|string|max:255',
            'status' => 'nullable|in:all,running,stopped,suspended,error,pending', 'page' => 'nullable|integer|min:1']);
        return $this->respond(function () use ($data) {
            $query = BandwagonVps::query();
            if ($search = trim($data['search'] ?? '')) {
                $query->where(function ($q) use ($search) {
                    foreach (['veid', 'name', 'hostname', 'imported_ip', 'info'] as $column) $q->orWhere($column, 'like', '%' . $search . '%');
                });
            }
            switch ($data['status'] ?? 'all') {
                case 'running': $query->where('live->ve_status', 'Running'); break;
                case 'stopped': $query->where('live->ve_status', 'Stopped'); break;
                case 'suspended': $query->where('info->suspended', true); break;
                case 'error': $query->where(fn ($q) => $q->whereNotNull('last_error')->orWhereNotNull('live_error')); break;
                case 'pending': $query->whereNull('synced_at'); break;
            }
            $page = $query->orderBy('id')->paginate(30, ['*'], 'page', $data['page'] ?? 1);
            $page->setCollection($page->getCollection()->map(fn ($vps) => array_merge($vps->toArray(), [
                'usage' => VpsData::usage($vps->info), 'stale' => !$vps->synced_at || $vps->synced_at->lt(now()->subMinutes(15)),
            ])));
            return $page;
        });
    }

    public function preview(Request $request)
    {
        $data = $request->validate(['csv' => 'required|string|max:262144']);
        return $this->respond(function () use ($data) {
            $rows = $this->importer->preview($this->importer->parse($data['csv']));
            $existing = BandwagonVps::whereIn('veid', array_column($rows, 'veid'))->pluck('veid')->all();
            return array_map(fn ($row) => array_merge($row, ['existing' => in_array($row['veid'], $existing, true)]), $rows);
        });
    }

    public function import(Request $request)
    {
        $data = $request->validate(['csv' => 'required|string|max:262144']);
        return $this->respond(fn () => $this->service->import($this->importer->parse($data['csv'])));
    }

    public function refresh(Request $request)
    {
        $data = $request->validate(['id' => 'nullable|integer|min:1|exists:v2_bandwagon_vps,id']);
        return $this->respond(function () use ($data) {
            $count = 0; $skipped = 0;
            $query = BandwagonVps::select('id');
            if (!empty($data['id'])) $query->where('id', $data['id']);
            $query->chunkById(100, function ($rows) use (&$count, &$skipped) {
                foreach ($rows as $vps) {
                    try { $this->service->enqueue($vps->id) ? $count++ : $skipped++; }
                    catch (BandwagonException $e) { $skipped++; }
                }
            });
            return ['queued' => $count, 'skipped' => $skipped];
        });
    }

    public function rename(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|min:1|exists:v2_bandwagon_vps,id', 'name' => 'present|nullable|string|max:80']);
        return $this->respond(function () use ($data) {
            $vps = BandwagonVps::findOrFail($data['id']);
            return $this->service->locked($vps->veid, function () use ($vps, $data) {
                $vps->update(['name' => trim($data['name'] ?? '')]);
                return true;
            });
        });
    }

    public function drop(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|min:1|exists:v2_bandwagon_vps,id']);
        return $this->respond(function () use ($data) {
            $vps = BandwagonVps::findOrFail($data['id']);
            return $this->service->locked($vps->veid, fn () => $vps->delete());
        });
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|min:1|exists:v2_bandwagon_vps,id',
            'confirm_veid' => 'required|string|max:20']);
        return $this->respond(fn () => $this->service->resetPassword($data['id'], $data['confirm_veid']));
    }
}
