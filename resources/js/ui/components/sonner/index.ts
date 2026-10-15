export { default as Toaster } from "./Sonner.vue"
// The toasts' state lives in vue-sonner's module: calling `toast` through the kit keeps one copy for the Toaster
// and every caller, whatever the node_modules layout.
export { toast } from "vue-sonner"
