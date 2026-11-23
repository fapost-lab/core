/**
 * Whether the flow form offers the expression engine: while more than one is installed, and also while the flow's
 * own engine is not among them (it left with a Solution or plugin). Otherwise validate and publish refuse the flow
 * with `expression_engine_unknown` and nothing on screen could choose another one.
 */
export function offersEngineChoice(installed: string[], current: string): boolean {
  return installed.length > 1 || !installed.includes(current)
}

/**
 * The engine the form sends, or undefined to send none, so the flow keeps its engine: nothing while there is no
 * choice to make, and nothing while the select still holds an engine that is not installed.
 */
export function engineToSend(installed: string[], current: string, chosen: string): string | undefined {
  return offersEngineChoice(installed, current) && installed.includes(chosen) ? chosen : undefined
}
