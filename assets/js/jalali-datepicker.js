(() => {
'use strict';

const MONTHS = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
const WEEKDAYS = ['ش','ی','د','س','چ','پ','ج'];
const FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
const EN_DIGITS = '0123456789';

const toEnglishDigits = value => String(value ?? '')
  .replace(/[۰-۹]/g, d => String(FA_DIGITS.indexOf(d)))
  .replace(/[٠-٩]/g, d => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));
const toPersianDigits = value => String(value ?? '').replace(/\d/g, d => FA_DIGITS[Number(d)]);
const pad = value => String(value).padStart(2, '0');

function div(a, b) { return Math.trunc(a / b); }
function mod(a, b) { return a - Math.trunc(a / b) * b; }

function gregorianToJalali(gy, gm, gd) {
  const gdm = [0,31,59,90,120,151,181,212,243,273,304,334];
  let jy = gy <= 1600 ? 0 : 979;
  gy -= gy <= 1600 ? 621 : 1600;
  const gy2 = gm > 2 ? gy + 1 : gy;
  let days = 365 * gy + div(gy2 + 3,4) - div(gy2 + 99,100) + div(gy2 + 399,400) - 80 + gd + gdm[gm - 1];
  jy += 33 * div(days,12053);
  days %= 12053;
  jy += 4 * div(days,1461);
  days %= 1461;
  if (days > 365) {
    jy += div(days - 1,365);
    days = (days - 1) % 365;
  }
  const jm = days < 186 ? 1 + div(days,31) : 7 + div(days - 186,30);
  const jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
  return [jy,jm,jd];
}

function jalaliToGregorian(jy, jm, jd) {
  let gy = jy <= 979 ? 621 : 1600;
  jy -= jy <= 979 ? 0 : 979;
  let days = 365 * jy + div(jy,33) * 8 + div((jy % 33) + 3,4) + 78 + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
  gy += 400 * div(days,146097);
  days %= 146097;
  if (days > 36524) {
    gy += 100 * div(--days,36524);
    days %= 36524;
    if (days >= 365) days++;
  }
  gy += 4 * div(days,1461);
  days %= 1461;
  if (days > 365) {
    gy += div(days - 1,365);
    days = (days - 1) % 365;
  }
  let gd = days + 1;
  const leap = (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0;
  const salA = [0,31,leap ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
  let gm = 1;
  while (gm <= 12 && gd > salA[gm]) gd -= salA[gm++];
  return [gy,gm,gd];
}

function isLeapJalali(year) {
  const [gy1,gm1,gd1] = jalaliToGregorian(year,1,1);
  const [gy2,gm2,gd2] = jalaliToGregorian(year + 1,1,1);
  const first = Date.UTC(gy1,gm1 - 1,gd1);
  const next = Date.UTC(gy2,gm2 - 1,gd2);
  return Math.round((next - first) / 86400000) === 366;
}

function daysInMonth(year, month) {
  if (month <= 6) return 31;
  if (month <= 11) return 30;
  return isLeapJalali(year) ? 30 : 29;
}

function validDate(year, month, day) {
  return Number.isInteger(year) && year >= 1200 && year <= 1600
    && Number.isInteger(month) && month >= 1 && month <= 12
    && Number.isInteger(day) && day >= 1 && day <= daysInMonth(year,month);
}

function parseInput(value) {
  const normalized = toEnglishDigits(value).replace(/[.-]/g,'/').replace(/\s*،\s*/g,'، ').trim();
  const match = normalized.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})(?:\s*(?:،|,)?\s*(\d{1,2}):(\d{2}))?/);
  if (!match) return null;
  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  if (!validDate(year,month,day)) return null;
  const hour = match[4] === undefined ? null : Number(match[4]);
  const minute = match[5] === undefined ? null : Number(match[5]);
  if (hour !== null && (hour < 0 || hour > 23 || minute < 0 || minute > 59)) return null;
  return {year,month,day,hour,minute};
}

function todayJalali() {
  const now = new Date();
  const [year,month,day] = gregorianToJalali(now.getFullYear(),now.getMonth() + 1,now.getDate());
  return {year,month,day,hour:now.getHours(),minute:now.getMinutes()};
}

function formatDate(date, withTime = false) {
  let value = `${date.year}/${pad(date.month)}/${pad(date.day)}`;
  if (withTime) value += `، ${pad(date.hour ?? 0)}:${pad(date.minute ?? 0)}`;
  return toPersianDigits(value);
}

let activeInput = null;
let viewYear = 0;
let viewMonth = 0;
let selected = null;
let isDateTime = false;

const picker = document.createElement('div');
picker.className = 'jalali-datepicker';
picker.hidden = true;
picker.setAttribute('role','dialog');
picker.setAttribute('aria-modal','false');
picker.setAttribute('aria-label','تقویم شمسی');
picker.innerHTML = `
  <div class="jalali-datepicker__head">
    <button type="button" class="jalali-datepicker__nav" data-jdp-next aria-label="ماه بعد">‹</button>
    <div class="jalali-datepicker__selectors">
      <select data-jdp-month aria-label="ماه"></select>
      <select data-jdp-year aria-label="سال"></select>
    </div>
    <button type="button" class="jalali-datepicker__nav" data-jdp-prev aria-label="ماه قبل">›</button>
  </div>
  <div class="jalali-datepicker__week" aria-hidden="true"></div>
  <div class="jalali-datepicker__days" data-jdp-days></div>
  <div class="jalali-datepicker__time" data-jdp-time hidden>
    <label>ساعت <input type="number" min="0" max="23" inputmode="numeric" data-jdp-hour></label>
    <span>:</span>
    <label>دقیقه <input type="number" min="0" max="59" inputmode="numeric" data-jdp-minute></label>
  </div>
  <div class="jalali-datepicker__actions">
    <button type="button" class="jalali-datepicker__action" data-jdp-today>امروز</button>
    <button type="button" class="jalali-datepicker__action" data-jdp-clear>پاک‌کردن</button>
    <button type="button" class="jalali-datepicker__action jalali-datepicker__action--primary" data-jdp-apply hidden>تأیید</button>
    <button type="button" class="jalali-datepicker__action" data-jdp-close>بستن</button>
  </div>`;
document.body.appendChild(picker);

const daysContainer = picker.querySelector('[data-jdp-days]');
const monthSelect = picker.querySelector('[data-jdp-month]');
const yearSelect = picker.querySelector('[data-jdp-year]');
const timeBlock = picker.querySelector('[data-jdp-time]');
const hourInput = picker.querySelector('[data-jdp-hour]');
const minuteInput = picker.querySelector('[data-jdp-minute]');
const applyButton = picker.querySelector('[data-jdp-apply]');
const clearButton = picker.querySelector('[data-jdp-clear]');
picker.querySelector('.jalali-datepicker__week').innerHTML = WEEKDAYS.map(day => `<span>${day}</span>`).join('');
monthSelect.innerHTML = MONTHS.map((month,index) => `<option value="${index + 1}">${month}</option>`).join('');
for (let year = 1200; year <= 1600; year++) {
  const option = document.createElement('option');
  option.value = String(year);
  option.textContent = toPersianDigits(year);
  yearSelect.appendChild(option);
}

function render() {
  monthSelect.value = String(viewMonth);
  yearSelect.value = String(viewYear);
  const [gy,gm,gd] = jalaliToGregorian(viewYear,viewMonth,1);
  const firstWeekday = (new Date(gy,gm - 1,gd).getDay() + 1) % 7;
  const count = daysInMonth(viewYear,viewMonth);
  const today = todayJalali();
  const cells = [];
  for (let i = 0; i < firstWeekday; i++) cells.push('<span class="jalali-datepicker__blank"></span>');
  for (let day = 1; day <= count; day++) {
    const selectedClass = selected && selected.year === viewYear && selected.month === viewMonth && selected.day === day ? ' is-selected' : '';
    const todayClass = today.year === viewYear && today.month === viewMonth && today.day === day ? ' is-today' : '';
    cells.push(`<button type="button" class="jalali-datepicker__day${selectedClass}${todayClass}" data-jdp-day="${day}" aria-label="${toPersianDigits(day)} ${MONTHS[viewMonth - 1]} ${toPersianDigits(viewYear)}">${toPersianDigits(day)}</button>`);
  }
  daysContainer.innerHTML = cells.join('');
  timeBlock.hidden = !isDateTime;
  applyButton.hidden = !isDateTime;
  clearButton.hidden = Boolean(activeInput?.required);
}

function positionPicker() {
  if (!activeInput || picker.hidden) return;
  if (window.matchMedia('(max-width: 600px)').matches) {
    picker.style.left = '12px';
    picker.style.right = '12px';
    picker.style.top = '50%';
    picker.style.transform = 'translateY(-50%)';
    picker.style.width = 'auto';
    return;
  }
  picker.style.right = 'auto';
  picker.style.transform = 'none';
  picker.style.width = '330px';
  const rect = activeInput.getBoundingClientRect();
  const popupHeight = picker.offsetHeight || (isDateTime ? 470 : 410);
  const top = rect.bottom + 8 + popupHeight > window.innerHeight ? Math.max(8, rect.top - popupHeight - 8) : rect.bottom + 8;
  const left = Math.min(Math.max(8, rect.right - 330), window.innerWidth - 338);
  picker.style.top = `${top}px`;
  picker.style.left = `${left}px`;
}

function commit(closeAfter = true) {
  if (!activeInput || !selected) return;
  if (isDateTime) {
    const hour = Math.max(0,Math.min(23,Number(toEnglishDigits(hourInput.value)) || 0));
    const minute = Math.max(0,Math.min(59,Number(toEnglishDigits(minuteInput.value)) || 0));
    selected.hour = hour;
    selected.minute = minute;
  }
  activeInput.value = formatDate(selected,isDateTime);
  activeInput.dispatchEvent(new Event('input',{bubbles:true}));
  activeInput.dispatchEvent(new Event('change',{bubbles:true}));
  if (closeAfter) closePicker();
}

function openPicker(input) {
  activeInput = input;
  isDateTime = input.matches('[data-jalali-datetime]');
  selected = parseInput(input.value) || todayJalali();
  if (isDateTime && selected.hour === null) {
    const now = new Date();
    selected.hour = now.getHours();
    selected.minute = now.getMinutes();
  }
  viewYear = selected.year;
  viewMonth = selected.month;
  hourInput.value = pad(selected.hour ?? 0);
  minuteInput.value = pad(selected.minute ?? 0);
  picker.hidden = false;
  picker.classList.add('is-open');
  input.setAttribute('aria-expanded','true');
  render();
  requestAnimationFrame(positionPicker);
}

function closePicker() {
  if (activeInput) activeInput.setAttribute('aria-expanded','false');
  picker.hidden = true;
  picker.classList.remove('is-open');
  activeInput = null;
}

function attach(input) {
  if (input.dataset.jdpReady === '1') return;
  input.dataset.jdpReady = '1';
  input.setAttribute('aria-haspopup','dialog');
  input.setAttribute('aria-expanded','false');
  input.autocomplete = 'off';
  input.readOnly = true;
  input.inputMode = 'none';
  input.classList.add('jalali-datepicker-input');

  const wrapper = document.createElement('span');
  wrapper.className = 'jalali-picker-field';
  input.parentNode.insertBefore(wrapper,input);
  wrapper.appendChild(input);
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'jalali-picker-trigger';
  button.setAttribute('aria-label','بازکردن تقویم شمسی');
  button.innerHTML = '<span aria-hidden="true">▦</span>';
  wrapper.appendChild(button);

  const open = event => {
    event.preventDefault();
    event.stopPropagation();
    openPicker(input);
  };
  button.addEventListener('click',open);
  input.addEventListener('click',open);
  input.addEventListener('focus',() => openPicker(input));
  input.addEventListener('input',() => {
    input.value = String(input.value || '').replace(/[.-]/g,'/').replace(/\s*،\s*/g,'، ');
  });
}

picker.addEventListener('click', event => {
  event.stopPropagation();
  const dayButton = event.target.closest('[data-jdp-day]');
  if (dayButton) {
    selected = selected || todayJalali();
    selected.year = viewYear;
    selected.month = viewMonth;
    selected.day = Number(dayButton.dataset.jdpDay);
    render();
    if (!isDateTime) commit(true);
    return;
  }
  if (event.target.closest('[data-jdp-prev]')) {
    viewMonth--;
    if (viewMonth < 1) { viewMonth = 12; viewYear--; }
    render();
    return;
  }
  if (event.target.closest('[data-jdp-next]')) {
    viewMonth++;
    if (viewMonth > 12) { viewMonth = 1; viewYear++; }
    render();
    return;
  }
  if (event.target.closest('[data-jdp-today]')) {
    selected = todayJalali();
    viewYear = selected.year;
    viewMonth = selected.month;
    hourInput.value = pad(selected.hour);
    minuteInput.value = pad(selected.minute);
    render();
    if (!isDateTime) commit(true);
    return;
  }
  if (event.target.closest('[data-jdp-clear]')) {
    if (activeInput && !activeInput.required) {
      activeInput.value = '';
      activeInput.dispatchEvent(new Event('input',{bubbles:true}));
      activeInput.dispatchEvent(new Event('change',{bubbles:true}));
    }
    closePicker();
    return;
  }
  if (event.target.closest('[data-jdp-apply]')) { commit(true); return; }
  if (event.target.closest('[data-jdp-close]')) { closePicker(); }
});

monthSelect.addEventListener('change',() => { viewMonth = Number(monthSelect.value); render(); });
yearSelect.addEventListener('change',() => { viewYear = Number(yearSelect.value); render(); });
[hourInput,minuteInput].forEach(input => input.addEventListener('input',() => {
  input.value = toEnglishDigits(input.value).replace(/\D/g,'').slice(0,2);
}));

document.addEventListener('click',event => {
  if (!picker.hidden && activeInput && !picker.contains(event.target) && !activeInput.closest('.jalali-picker-field')?.contains(event.target)) closePicker();
});
document.addEventListener('keydown',event => { if (event.key === 'Escape' && !picker.hidden) closePicker(); });
window.addEventListener('resize',positionPicker);
window.addEventListener('scroll',positionPicker,true);

function initialize(root = document) {
  root.querySelectorAll('[data-jalali-date],[data-jalali-datetime]').forEach(attach);
}

initialize();
window.MousaviJalaliPicker = { initialize, gregorianToJalali, jalaliToGregorian, parseInput, formatDate, daysInMonth };
})();
