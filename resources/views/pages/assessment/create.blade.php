<x-page :title="$type->name . ' — Details'">
  <div class="w-full px-4 py-8 sm:w-3/4 sm:px-0 mx-auto">
    <h2 class="text-lg font-medium leading-6 text-gray-900">New {{ $type->name }}</h2>
    <p class="mt-1 text-sm text-gray-500">Enter the {{ strtolower($type->name) }} details.</p>

    <form method="POST" action="{{ route('reporting.assessment.store') }}" class="mt-6">
      @csrf
      <input type="hidden" name="assessment_type_id" value="{{ $type->id }}">

      <div class="grid grid-cols-4 gap-6">
        <div class="col-span-4 sm:col-span-1">
          <label for="offering_id" class="block text-sm font-medium text-gray-700">Class</label>
          <select required name="offering_id" id="offering_id"
            class="block w-full px-3 py-2 mt-1 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-gray-900 focus:border-gray-900 sm:text-sm">
            <option value="">Select class</option>
            @foreach($offerings->sortBy('grade_id') as $offering)
            <option value="{{ $offering->id }}" @selected(old('offering_id', $selectedOffering) == $offering->id)>
              {{ $offering->grade->name ?? '' }} {{ $offering->name }}
            </option>
            @endforeach
          </select>
        </div>

        {{-- A term-less type (NAT) is not part of a term: hide the Term field entirely. --}}
        @unless($isTermLess ?? false)
        <div class="col-span-4 sm:col-span-1">
          <label for="term_id" class="block text-sm font-medium text-gray-700">Term</label>
          <select required name="term_id" id="term_id"
            class="block w-full px-3 py-2 mt-1 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-gray-900 focus:border-gray-900 sm:text-sm">
            <option value="">Select term</option>
            @foreach($terms as $term)
            <option value="{{ $term->id }}" @selected(old('term_id', $selectedTerm) == $term->id)>
              {{ $term->name }}
            </option>
            @endforeach
          </select>
        </div>
        @endunless

        <div class="col-span-4 sm:col-span-2">
          <label for="subject_id" class="block text-sm font-medium text-gray-700">Subject</label>
          <select required name="subject_id" id="subject_id"
            class="block w-full px-3 py-2 mt-1 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-gray-900 focus:border-gray-900 sm:text-sm">
            <option value="">Select subject</option>
            @foreach($subjects as $subject)
            <option value="{{ $subject->id }}" @selected(old('subject_id', $selectedSubject) == $subject->id)>
              {{ $subject->name }}
            </option>
            @endforeach
          </select>
          @if($subjects->isEmpty() && ($selectedOffering || $selectedTerm))
          <p class="text-xs text-gray-500 mt-1">Select {{ ($isTermLess ?? false) ? 'a class' : 'both class and term' }} first, then reload.</p>
          @endif
        </div>

        <div class="col-span-4 sm:col-span-2">
          <label for="name" class="block text-sm font-medium text-gray-700">{{ $type->name }} name</label>
          @if (!empty($slots))
            <select required name="name" id="name"
              class="block w-full px-3 py-2 mt-1 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-gray-900 focus:border-gray-900 sm:text-sm">
              @foreach ($slots as $slot)
                <option value="{{ $slot }}" @selected(old('name') === $slot)>{{ $slot }}</option>
              @endforeach
            </select>
          @else
            <input required type="text" name="name" id="name" value="{{ old('name') }}"
              class="block w-full px-3 py-2 mt-1 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-gray-900 focus:border-gray-900 sm:text-sm">
          @endif
        </div>

        @if (empty($slots))<div class="col-span-4 sm:col-span-1">
          <label for="date" class="block text-sm font-medium text-gray-700">Date</label>
          <input type="date" name="date" id="date" value="{{ old('date') }}"
            class="block w-full px-3 py-2 mt-1 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-gray-900 focus:border-gray-900 sm:text-sm">
        </div>@endif

        <div class="col-span-4 sm:col-span-1">
          <label for="max_score" class="block text-sm font-medium text-gray-700">Maximum score</label>
          <input required type="number" min="1" name="max_score" id="max_score" value="{{ old('max_score', $defaultMax) }}"
            class="block w-full px-3 py-2 mt-1 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-gray-900 focus:border-gray-900 sm:text-sm">
        </div>

        <div class="col-span-4 text-right">
          <button type="submit"
            class="inline-flex justify-center px-4 py-2 text-sm font-medium text-white bg-gray-800 border border-transparent rounded-md shadow-sm hover:bg-gray-900">
            Next
          </button>
        </div>
      </div>
    </form>
  </div>
</x-page>
