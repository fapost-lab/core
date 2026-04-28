export type FormFieldType = 'text' | 'checkbox' | 'select'

export interface FormField {
    id: string
    type: FormFieldType
    label: string
    required?: boolean
    placeholder?: string
    options?: string[]
}

export interface FormDefinition {
    id: string
    title?: string
    fields: FormField[]
}

export type FormAnswers = Record<string, string | boolean>

export interface FormSubmitResponse {
    success: boolean
    message?: string
}
