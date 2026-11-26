{{-- reports/projects' styles: its themes (--pj-* colours, theme-switchgear / theme-calm / theme-amber) and its motion. Shared
     with the dashboard, which opens the same project panel (reports.partials._project-panel). --}}
    <style>
        .work-summary:fullscreen { background: #f8fafc; padding: 24px; overflow-y: auto; max-width: none; }
        .work-summary:fullscreen .hide-in-fullscreen { display: none !important; }
        /* this page's range calendar: weekday names and month arrows readable on the dark header */
        .work-summary-calendar span.flatpickr-weekday { color: #cbd5e1; }
        .work-summary-calendar .flatpickr-months .flatpickr-prev-month svg, .work-summary-calendar .flatpickr-months .flatpickr-next-month svg { fill: #fff; }
        .work-summary-calendar .flatpickr-months .flatpickr-prev-month:hover svg, .work-summary-calendar .flatpickr-months .flatpickr-next-month:hover svg { fill: #93c5fd; }
        /* the panel's project: a short fade in when it opens or swaps to another card */
        .project-card-full { animation: project-card-in 260ms cubic-bezier(0.22, 1, 0.36, 1) both; }
        @keyframes project-card-in { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
        /* arriving on the page (and opening the panel): each production line runs from the start to today, the cards a beat
           apart (--i: the card's place); the bars of the panel grow. Only on arrival - the live refresh keeps the cards */
        .curve-run { animation: curve-run 1100ms cubic-bezier(0.22, 1, 0.36, 1) both; animation-delay: calc(min(var(--i, 0), 24) * 40ms + 120ms); }
        @keyframes curve-run { from { clip-path: inset(-4px 100% -4px 0); } to { clip-path: inset(-4px -4px -4px 0); } }
        .curve-plan { animation: curve-fade 500ms ease-out both; animation-delay: calc(min(var(--i, 0), 24) * 40ms); }
        @keyframes curve-fade { from { opacity: 0; } to { opacity: 1; } }
        .dot-pop { animation: dot-pop 380ms cubic-bezier(0.22, 1, 0.36, 1) 1000ms both; }
        @keyframes dot-pop { from { opacity: 0; scale: 0.2; } to { opacity: 1; scale: 1; } }
        .bar-grow { transform-origin: left; animation: bar-grow 800ms cubic-bezier(0.22, 1, 0.36, 1) 150ms both; }
        @keyframes bar-grow { from { transform: scaleX(0); } to { transform: scaleX(1); } }
        .bar-rise { transform-origin: bottom; animation: bar-rise 700ms cubic-bezier(0.22, 1, 0.36, 1) both; animation-delay: calc(var(--b, 0) * 35ms + 300ms); }
        @keyframes bar-rise { from { transform: scaleY(0); } to { transform: scaleY(1); } }
        .live-ripple { animation-duration: 2s; }
        .bars-rise { transform-box: fill-box; transform-origin: bottom; animation: bars-rise 700ms cubic-bezier(0.22, 1, 0.36, 1) both; animation-delay: calc(min(var(--i, 0), 24) * 40ms + 60ms); }
        @keyframes bars-rise { from { transform: scaleY(0); } to { transform: scaleY(1); } }
        @media (prefers-reduced-motion: reduce) { .project-card-full, .bars-rise, .curve-run, .curve-plan, .dot-pop, .bar-grow, .bar-rise { animation: none; } }

        /* the data colours, as variables: projectSummary.palette() reads them for the lines and bars it draws */
        .work-summary { --pj-progress: #0B1F3A; --pj-done: #0F766E; --pj-slow: #ea580c; --pj-behind: #dc2626; --pj-idle: #cbd5e1; --pj-plan: #64748b; }

        /* Amber Minimal (21st.dev / tweakcn "amber-minimal"): white, near-black type, amber #f59e0b as the one accent - the
           active controls, the chosen card, the live dot and the ticks; green for done (this page); red / orange stay for behind.
           Remove the class theme-amber from the page to go back to navy / teal. Anuphan stays the typeface (PRODUCT.md). */
        .theme-amber { --pj-progress: #262626; --pj-done: #16a34a; --pj-plan: #9ca3af; --pj-idle: #e5e7eb; --pj-tick: #f59e0b; }
        .theme-amber .text-ae-navy, .theme-amber .text-slate-900, .theme-amber .text-slate-800 { color: #262626; }
        .theme-amber .bg-ae-navy { background-color: #262626; }
        .theme-amber .bg-blue-600, .theme-amber .bg-slate-900 { background-color: #f59e0b; color: #000; }
        .theme-amber .bg-blue-50 { background-color: #fffbeb; }
        .theme-amber .bg-blue-100, .theme-amber .hover\:bg-blue-100:hover { background-color: #fef3c7; }
        .theme-amber .text-blue-700, .theme-amber .text-blue-900, .theme-amber .hover\:text-blue-900:hover { color: #92400e; }
        .theme-amber .border-blue-600, .theme-amber .border-slate-900 { border-color: #f59e0b; }
        .theme-amber select:focus { border-color: #f59e0b; --tw-ring-color: #f59e0b; }
        .theme-amber .ring-blue-600, .theme-amber .focus-visible\:ring-blue-600:focus-visible { --tw-ring-color: #f59e0b; }
        .theme-amber .bg-teal-700 { background-color: #16a34a; }
        .theme-amber .ring-teal-200 { --tw-ring-color: #bbf7d0; }
        .theme-amber .text-teal-700 { color: #15803d; }
        .theme-amber .text-teal-800 { color: #166534; }
        .theme-amber .text-emerald-700, .theme-amber .text-emerald-800 { color: #92400e; }
        .theme-amber .bg-teal-50 { background-color: #f0fdf4; }
        .theme-amber .bg-emerald-50 { background-color: #fffbeb; }
        .theme-amber .bg-emerald-100 { background-color: #fef3c7; }
        .theme-amber .bg-emerald-500, .theme-amber .bg-emerald-600 { background-color: #f59e0b; }
        .theme-amber .ring-emerald-200 { --tw-ring-color: #fde68a; }
        .theme-amber .ring-emerald-400 { --tw-ring-color: #f59e0b; }
        .theme-amber .bg-\[\#eef2ff\], .theme-amber .bg-\[\#eef2f7\] { background-color: #f3f4f6; }
        .theme-amber .text-\[\#3730a3\] { color: #4b5563; }
        .theme-amber .ring-\[\#c7d2fe\] { --tw-ring-color: #e5e7eb; }
        .theme-amber .rounded-xl, .theme-amber .rounded-lg { border-radius: 0.375rem; }
        .theme-amber ::selection { background: #fde68a; }
        /* Light blue (theme-calm): one family for what is fine - light blue the choice (pale fill, deep blue type), blue the
           progress line, sky the live work and the ticks - then green done, amber behind the plan, red past the due date,
           grey the rest. Switch: the page's theme class (theme-calm / theme-amber / none). */
        .theme-calm { --pj-progress: #0284c7; --pj-done: #16a34a; --pj-slow: #d97706; --pj-behind: #dc2626; --pj-idle: #e2e8f0; --pj-plan: #94a3b8; --pj-tick: #38bdf8; }
        .theme-calm .bg-blue-600, .theme-calm .bg-slate-900 { background-color: #e0f2fe; color: #075985; }
        .theme-calm .bg-slate-900 .text-white, .theme-calm .bg-slate-900 .text-slate-300 { color: #0c4a6e; }
        .theme-calm .border-slate-900 { border-color: #7dd3fc; }
        .theme-calm .shadow-\[0_0_0_1px_\#0f172a\] { box-shadow: 0 0 0 1px #7dd3fc; }
        .theme-calm .count-badge { background-color: #38bdf8; color: #082f49; }
        .theme-calm .bg-blue-50 { background-color: #f0f9ff; }
        .theme-calm .text-ae-navy { color: #1f2937; }
        .theme-calm .ring-blue-600, .theme-calm .focus-visible\:ring-blue-600:focus-visible { --tw-ring-color: #38bdf8; }
        .theme-calm .border-blue-600 { border-color: #38bdf8; }
        .theme-calm .text-blue-700 { color: #0369a1; }
        .theme-calm .bg-\[\#eef2ff\] { background-color: #f1f5f9; }
        .theme-calm .text-\[\#3730a3\] { color: #475569; }
        .theme-calm .ring-\[\#c7d2fe\] { --tw-ring-color: #e2e8f0; }
        .theme-calm .bg-teal-700 { background-color: #16a34a; }
        .theme-calm .bg-teal-50 { background-color: #f0fdf4; }
        .theme-calm .text-teal-700 { color: #15803d; }
        .theme-calm .text-teal-800 { color: #166534; }
        .theme-calm .ring-teal-200 { --tw-ring-color: #bbf7d0; }
        .theme-calm .bg-emerald-500, .theme-calm .bg-emerald-600 { background-color: #0ea5e9; }
        .theme-calm .bg-emerald-50 { background-color: #f0f9ff; }
        .theme-calm .bg-emerald-100 { background-color: #e0f2fe; }
        .theme-calm .text-emerald-700, .theme-calm .text-emerald-800 { color: #0369a1; }
        .theme-calm .ring-emerald-200 { --tw-ring-color: #bae6fd; }
        .theme-calm .ring-emerald-400 { --tw-ring-color: #0ea5e9; }
        .theme-calm .bg-orange-50 { background-color: #fffbeb; }
        .theme-calm .bg-orange-500 { background-color: #d97706; }
        .theme-calm .text-orange-700, .theme-calm .text-orange-800 { color: #b45309; }
        .theme-calm .ring-orange-200 { --tw-ring-color: #fde68a; }
        .theme-calm select:focus { border-color: #38bdf8; --tw-ring-color: #38bdf8; }
        .theme-calm ::selection { background: #bae6fd; }
        /* Switchgear (theme-switchgear), from what the company builds: copper for the progress - the busbar, the one accent
           (the % line, the ticks, the chosen card, links); steel graphite for what is chosen (a cabinet's frame); RAL 7035
           light grey for the quiet chips; the status words lit like the pilot lamps on a cabinet's door - green done, amber
           behind the plan, red past the due date, blue live work. Switch: the page's theme class. */
        .theme-switchgear { --pj-progress: #b4622d; --pj-done: #16a34a; --pj-slow: #d97706; --pj-behind: #dc2626; --pj-idle: #d6d3d1; --pj-plan: #a8a29e; --pj-tick: #d39a73; }
        .theme-switchgear .bg-blue-600, .theme-switchgear .bg-slate-900 { background-color: #262a30; color: #fff; }
        .theme-switchgear .border-slate-900 { border-color: #262a30; }
        .theme-switchgear .shadow-\[0_0_0_1px_\#0f172a\] { box-shadow: 0 0 0 1px #262a30; }
        .theme-switchgear .count-badge { background-color: #2563eb; color: #fff; }
        .theme-switchgear .bg-blue-50 { background-color: #fbf1ea; }
        .theme-switchgear .text-ae-navy { color: #1f2329; }
        .theme-switchgear .ring-blue-600, .theme-switchgear .focus-visible\:ring-blue-600:focus-visible { --tw-ring-color: #b4622d; }
        .theme-switchgear .border-blue-600 { border-color: #b4622d; }
        .theme-switchgear .text-blue-700 { color: #9a4f22; }
        .theme-switchgear .bg-\[\#eef2ff\] { background-color: #eeeeea; }
        .theme-switchgear .text-\[\#3730a3\] { color: #4b5563; }
        .theme-switchgear .ring-\[\#c7d2fe\] { --tw-ring-color: #d9d9d3; }
        .theme-switchgear .bg-teal-700 { background-color: #16a34a; }
        .theme-switchgear .bg-teal-50 { background-color: #f0fdf4; }
        .theme-switchgear .text-teal-700 { color: #15803d; }
        .theme-switchgear .text-teal-800 { color: #166534; }
        .theme-switchgear .ring-teal-200 { --tw-ring-color: #bbf7d0; }
        .theme-switchgear .bg-emerald-500, .theme-switchgear .bg-emerald-600 { background-color: #2563eb; }
        .theme-switchgear .bg-emerald-50 { background-color: #eff6ff; }
        .theme-switchgear .bg-emerald-100 { background-color: #dbeafe; }
        .theme-switchgear .text-emerald-700, .theme-switchgear .text-emerald-800 { color: #1d4ed8; }
        .theme-switchgear .ring-emerald-200 { --tw-ring-color: #bfdbfe; }
        .theme-switchgear .ring-emerald-400 { --tw-ring-color: #2563eb; }
        .theme-switchgear .bg-orange-50 { background-color: #fffbeb; }
        .theme-switchgear .bg-orange-500 { background-color: #d97706; }
        .theme-switchgear .text-orange-700, .theme-switchgear .text-orange-800 { color: #b45309; }
        .theme-switchgear .ring-orange-200 { --tw-ring-color: #fde68a; }
        /* the pilot lamps: a status dot glows in its own colour */
        .theme-switchgear .lamp.bg-teal-700 { box-shadow: 0 0 0 2px #fff, 0 0 8px 1px rgba(22, 163, 74, .65); }
        .theme-switchgear .lamp.bg-orange-500 { box-shadow: 0 0 0 2px #fff, 0 0 8px 1px rgba(217, 119, 6, .65); }
        .theme-switchgear .lamp.bg-red-600 { box-shadow: 0 0 0 2px #fff, 0 0 8px 1px rgba(220, 38, 38, .6); }
        .theme-switchgear .lamp.bg-slate-400 { box-shadow: 0 0 0 2px #fff; }
        .theme-switchgear select:focus { border-color: #b4622d; --tw-ring-color: #b4622d; }
        .theme-switchgear ::selection { background: #f3d9c6; }
        /* the amber filled headings (this week): dark type on amber, not white */
        .theme-amber .bg-slate-900 .text-white, .theme-amber .bg-slate-900 .text-slate-300 { color: #451a03; }
        .theme-amber .shadow-\[0_0_0_1px_\#0f172a\] { box-shadow: 0 0 0 1px #f59e0b; }
        /* a late spot reached from "ช้า N จุด": its Sub Task lights up a moment */
        @keyframes late-flash { 0%, 35% { background-color: #ffedd5; box-shadow: inset 3px 0 0 #ea580c; } 100% { background-color: transparent; box-shadow: inset 3px 0 0 transparent; } }
        .late-flash { animation: late-flash 2.2s ease-out; }
        @media (prefers-reduced-motion: reduce) { .late-flash { animation: none; background-color: #ffedd5; box-shadow: inset 3px 0 0 #ea580c; } }
    </style>
