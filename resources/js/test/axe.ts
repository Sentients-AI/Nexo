// Runs axe-core against a mounted component (NFR-A11Y-04) and returns its
// violations as readable strings, so a failing test shows what and where.
//
// colour-contrast is off: jsdom does not compute styles, and token contrast
// is enforced by resources/js/design/tokens-contrast.test.ts. `region` is off
// because components are tested outside page landmarks.
import axe from 'axe-core';

export async function axeViolations(element: Element): Promise<string[]> {
    const results = await axe.run(element, {
        rules: {
            'color-contrast': { enabled: false },
            region: { enabled: false },
        },
    });

    return results.violations.flatMap((violation) =>
        violation.nodes.map((node) => `${violation.id}: ${violation.help} → ${node.target.join(' ')}`),
    );
}
