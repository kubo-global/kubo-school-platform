<x-page title="Log in" bg-color='bg-[#0f1030]'>
    <a href="{{ route('student-login.select-grade') }}"
       style="top: max(1rem, env(safe-area-inset-top, 1rem))"
       class="fixed z-20 inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white transition border rounded-lg shadow-lg right-4 bg-white/20 hover:bg-white/35 border-white/30 backdrop-blur-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"/></svg>
        Student login
    </a>
    {{-- Hand-drawn nudge so it's obvious the corner button switches to the student login. --}}
    <div class="fixed z-20 items-end hidden gap-1 select-none sm:flex top-[4.25rem] right-12 text-brand-gold pointer-events-none">
        <span class="mb-3 text-sm italic -rotate-3">Not a teacher? Click here</span>
        <svg class="text-brand-gold/80 w-12 h-16" viewBox="0 0 50 64" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
            <path d="M2 44 C 20 54, 40 48, 41 13"/>
            <path d="M34 20 L 41 13 L 48 20"/>
        </svg>
    </div>
    {{-- Immersive: form lives directly on the aurora, no white panel. --}}
    <div class="relative flex flex-col items-center justify-center min-h-screen px-6 py-12 overflow-hidden bg-[#0f1030]">
        <x-aurora-bg />
        <div class="relative z-10 w-full max-w-sm">
            <div class="mb-10 text-center">
                <svg class="w-auto mx-auto text-white h-20 fill-current drop-shadow" viewBox="0 0 240 240" xmlns="http://www.w3.org/2000/svg">
                    <path d="M228.39 76.13l11.24-6.48L125.94 4 1 76.13 38.9 98v87.53l87 50.24 87-50.24V85l-87 50.26-75.77-43.73 64.57-37.28-11.24-6.48L38.9 85l-15.43-8.87L125.94 17l102.45 59.13zM50.14 104.49l75.8 43.84 75.79-43.84V179l-75.79 43.83L50.14 179v-74.51z"/>
                </svg>
                <h2 class="mt-6 text-3xl font-extrabold tracking-tight text-white">Welcome to KUBO</h2>
                <p class="mt-3 text-lg italic font-light tracking-wide text-indigo-200/90">Empowering your school</p>
            </div>

            @foreach ($errors->all() as $error)
                <div class="flex items-center gap-2 p-3 mb-4 text-sm text-white border rounded-lg bg-red-500/25 border-red-300/40">
                    <svg class="w-5 h-5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                    {{ $error }}
                </div>
            @endforeach

            <form method="POST" action="{{ route('login') }}" class="space-y-5">
                @csrf
                {{-- Type-to-search account picker — scales to schools with many staff, unlike a long dropdown. --}}
                <div x-data="{
                        users: {{ \Illuminate\Support\Js::from($users->map(fn ($u) => ['id' => $u->id, 'name' => trim($u->first_name.' '.$u->last_name)])->values()) }},
                        query: '', selectedId: '', open: false, active: 0,
                        get matches() {
                            const q = this.query.trim().toLowerCase();
                            return (q ? this.users.filter(u => u.name.toLowerCase().includes(q)) : this.users).slice(0, 8);
                        },
                        get totalMatches() {
                            const q = this.query.trim().toLowerCase();
                            return (q ? this.users.filter(u => u.name.toLowerCase().includes(q)) : this.users).length;
                        },
                        sync() {
                            const q = this.query.trim().toLowerCase();
                            const exact = this.users.find(u => u.name.toLowerCase() === q);
                            this.selectedId = exact ? exact.id : (this.matches.length === 1 ? this.matches[0].id : '');
                        },
                        pick(u) { this.query = u.name; this.selectedId = u.id; this.open = false; },
                     }"
                     @click.away="open = false" class="relative">
                    <label for="accountSearch" class="block mb-1.5 text-sm font-medium text-white/90">Your account</label>
                    <input id="accountSearch" type="text" x-model="query" autocomplete="off"
                        role="combobox" aria-autocomplete="list" aria-controls="accountList" :aria-expanded="open && matches.length ? 'true' : 'false'"
                        @focus="open = true" @input="open = true; active = 0; sync()"
                        @keydown.arrow-down.prevent="open = true; active = Math.min(active + 1, matches.length - 1)"
                        @keydown.arrow-up.prevent="active = Math.max(active - 1, 0)"
                        @keydown.enter.prevent="matches[active] && pick(matches[active])"
                        @keydown.escape="open = false"
                        placeholder="Type your name"
                        class="w-full px-3.5 py-2.5 text-white border rounded-lg bg-white/10 border-white/25 placeholder-white/60 backdrop-blur-sm focus:outline-none focus:bg-white/20 focus:border-white/60 focus:ring-2 focus:ring-white/40 transition">
                    <input type="hidden" name="id" :value="selectedId">
                    <ul id="accountList" role="listbox" x-show="open && matches.length" x-cloak x-transition.opacity
                        class="absolute z-10 w-full mt-1 overflow-auto text-sm bg-white shadow-lg max-h-60 rounded-lg ring-1 ring-black/10">
                        <template x-for="(u, i) in matches" :key="u.id">
                            <li role="option" :aria-selected="i === active" @click="pick(u)" @mouseenter="active = i"
                                :class="i === active ? 'bg-indigo-600 text-white' : 'text-gray-900'"
                                class="px-3 py-2 cursor-pointer" x-text="u.name"></li>
                        </template>
                        {{-- Sticky truncation note so a full staff list never looks like "only 8 people". --}}
                        <li x-show="totalMatches > matches.length" role="presentation"
                            class="sticky bottom-0 px-3 py-2 text-xs border-t bg-gray-50 text-gray-600 border-gray-100">
                            <span x-text="query
                                ? 'Showing ' + matches.length + ' of ' + totalMatches + ' — keep typing to narrow'
                                : 'Showing ' + matches.length + ' of ' + totalMatches + ' staff — type your name to find yourself'"></span>
                        </li>
                    </ul>
                </div>
                <div>
                    <label for="password" class="block mb-1.5 text-sm font-medium text-white/90">Password</label>
                    <input name="password" id="password" type="password" required
                        class="w-full px-3.5 py-2.5 text-white border rounded-lg appearance-none bg-white/10 border-white/25 placeholder-white/60 backdrop-blur-sm focus:outline-none focus:bg-white/20 focus:border-white/60 focus:ring-2 focus:ring-white/40 transition">
                    {{-- A school has no mail to send a reset link through: whoever
                         manages the staff accounts resets it, so say who to ask. --}}
                    <p class="mt-1.5 text-sm text-white/70">Forgot it? The headmaster can reset it for you.</p>
                </div>
                {{-- Off by default: many staff share one computer in the staff room,
                     and a remembered session there would open for the next person. --}}
                <label class="flex items-start gap-2 text-sm cursor-pointer text-white/90">
                    <input type="checkbox" name="remember" value="1" class="mt-0.5 rounded">
                    <span>Stay signed in <span class="text-white/70">(only on a device nobody else uses)</span></span>
                </label>
                <button type="submit"
                    class="flex justify-center w-full px-4 py-2.5 text-sm font-semibold text-indigo-700 transition bg-white rounded-lg shadow-lg hover:bg-indigo-50 focus:outline-none focus:ring-2 focus:ring-white/70">
                    Log in
                </button>
            </form>
        </div>
    </div>
</x-page>
  