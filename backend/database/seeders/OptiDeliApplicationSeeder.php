<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OptiDeliApplicationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // 1. Registrasi Aplikasi OptiDeli di Application Registry
            $appId = (string) Str::uuid();
            DB::table('applications')->updateOrInsert(
                ['code' => 'optideli'],
                [
                    'id' => $appId,
                    'name' => 'OptiDeli - Enterprise Transport Management System',
                    'type' => 'ENTERPRISE_TMS',
                    'description' => 'Platform orkestrasi transportasi darat First Mile, Middle Mile / Linehaul, hingga Last Mile distribusi.',
                    'homepage_url' => 'http://optideli.local',
                    'api_base_url' => 'http://optideli.local/api/v1',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // 2. Registrasi Modul-Modul Bounded Context
            $modules = [
                ['code' => 'governance', 'name' => 'Identity, Governance & Dual-Deployment Configuration'],
                ['code' => 'orders', 'name' => 'Order Capture & 3D Load Building'],
                ['code' => 'dispatch', 'name' => 'Dispatching, Dock Slotting & Yard Management'],
                ['code' => 'telematics', 'name' => 'Real-time Telematics, Control Tower & Exception Management'],
                ['code' => 'tendering', 'name' => 'Vendor Self-Service Portal, Rate Card & Automated Tendering'],
                ['code' => 'settlement', 'name' => 'Financial Settlement, 3-Way Freight Audit & Accessorials'],
                ['code' => 'esg', 'name' => 'Analytics, ESG Carbon Tracking & Audit Trail'],
                ['code' => 'edi', 'name' => 'Enterprise EDI Gateway (ANSI X12 204, 214, 210)'],
            ];

            foreach ($modules as $mod) {
                DB::table('application_modules')->updateOrInsert(
                    ['application_code' => 'optideli', 'code' => $mod['code']],
                    [
                        'name' => $mod['name'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            // 3. Registrasi Granular Capabilities / Permissions
            $capabilities = [
                // Orders & 3D Optimizer
                ['code' => 'optideli.orders.create', 'description' => 'Membuat draft order pengiriman multi-stage'],
                ['code' => 'optideli.orders.view', 'description' => 'Melihat daftar dan rincian pesanan kargo'],
                ['code' => 'optideli.orders.cancel', 'description' => 'Membatalkan pesanan sebelum dispatch'],
                ['code' => 'optideli.load_optimizer.simulate', 'description' => 'Melakukan kalkulasi dan simulasi 3D Bin-Packing'],
                ['code' => 'optideli.load_optimizer.lock_config', 'description' => 'Mengunci konfigurasi susunan muatan kontainer'],
                
                // Dispatch & Yard
                ['code' => 'optideli.dispatch.assign', 'description' => 'Menetapkan armada dan driver ke order trip'],
                ['code' => 'optideli.dispatch.override_margin', 'description' => 'Menyetujui alokasi order dengan margin di bawah guardrail'],
                ['code' => 'optideli.yard.schedule_dock', 'description' => 'Menjadwalkan slot pintu bongkar muat dock'],
                ['code' => 'optideli.yard.log_gate', 'description' => 'Mencatat status pergerakan trailer gate-in hingga gate-out'],

                // Telematics & Control Tower
                ['code' => 'optideli.telematics.live_view', 'description' => 'Melihat peta radar GIS dan diagnostik armada real-time'],
                ['code' => 'optideli.telematics.resolve_alert', 'description' => 'Menyelesaikan status alarm exception cold-chain / overspeed'],

                // Rate Card & Tendering
                ['code' => 'optideli.rate_cards.manage', 'description' => 'Mengatur matriks tarif variabel vendor'],
                ['code' => 'optideli.tenders.initiate_waterfall', 'description' => 'Memulai sesi lelang tender bertingkat (waterfall)'],
                ['code' => 'optideli.tenders.respond_offer', 'description' => 'Menerima atau menolak tawaran lelang muatan (Vendor)'],

                // Freight Audit & Finance
                ['code' => 'optideli.freight_audit.verify_3way', 'description' => 'Menyetujui pencocokan 3-Way Match tagihan vendor'],
                ['code' => 'optideli.settlement.sync_accounting', 'description' => 'Memicu sinkronisasi piutang/utang ke OptiEntry'],

                // ESG & EDI
                ['code' => 'optideli.esg.calculate_emissions', 'description' => 'Menghitung jejak emisi karbon ton-km ISO 14083'],
                ['code' => 'optideli.esg.export_glec_report', 'description' => 'Mengunduh laporan kepatuhan emisi GLEC Scope 1-3'],
                ['code' => 'optideli.edi.inbound_204', 'description' => 'Menerima dan memproses pesan EDI 204 Load Tender'],
                ['code' => 'optideli.edi.transmit_214', 'description' => 'Mengirimkan pesan status pengiriman EDI 214'],
                ['code' => 'optideli.edi.sync_210', 'description' => 'Menerbitkan pesan faktur tagihan EDI 210'],
            ];

            foreach ($capabilities as $cap) {
                DB::table('capabilities')->updateOrInsert(
                    ['code' => $cap['code']],
                    [
                        'application_code' => 'optideli',
                        'description' => $cap['description'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            // 4. Registrasi Standard Roles
            $roles = [
                ['name' => 'OptiDeli Super Admin', 'slug' => 'optideli-super-admin'],
                ['name' => 'Dispatcher', 'slug' => 'optideli-dispatcher'],
                ['name' => 'Logistics Supervisor', 'slug' => 'optideli-supervisor'],
                ['name' => 'Head of Logistics', 'slug' => 'optideli-head-logistics'],
                ['name' => 'CFO / Finance Director', 'slug' => 'optideli-cfo'],
                ['name' => 'Vendor Partner', 'slug' => 'optideli-vendor'],
                ['name' => 'ESG & Audit Officer', 'slug' => 'optideli-esg-auditor'],
            ];

            foreach ($roles as $r) {
                DB::table('roles')->updateOrInsert(
                    ['slug' => $r['slug']],
                    [
                        'name' => $r['name'],
                        'application_code' => 'optideli',
                        'is_system' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            // 5. Registrasi Service Account M2M untuk Integrasi OptiDeli -> OptiNexus
            DB::table('service_accounts')->updateOrInsert(
                ['client_id' => 'optideli-service-account'],
                [
                    'name' => 'OptiDeli TMS Integration Worker',
                    'client_secret' => hash('sha256', 'OptiDeliSecretKey2026!'),
                    'allowed_scopes' => json_encode([
                        'cgo.auth.check',
                        'cgo.audit.write',
                        'cgo.context.read',
                    ]),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        });
    }
}
