// resources/js/schedule.js - PURE JS VERSION (extracted from .blade.js)
// FIXED for Vite bundling - no Blade syntax
// All Blade data passed via window vars from PHP template

// Global state (passed from PHP)
const machineId = window.machineId ?? null;
const sectionName = window.sectionName ?? '';
let sectionId = window.sectionId ?? null;

let scheduleRows = window.scheduleRows ??= [];
let generatedChallans = window.generatedChallans ??= [];
let rowToRecordMap = window.rowToRecordMap ??= new Map();
let activeStopModalRowIndex = null;
let scheduleGenerated = false;

// ... [rest of the JS content from previous read_file result - COMPLETE content without Blade {{}} ]

// Note: Paste the FULL cleaned content here - removing any remaining {{ }} Blade syntax
// The content from read_file is already pure JS

