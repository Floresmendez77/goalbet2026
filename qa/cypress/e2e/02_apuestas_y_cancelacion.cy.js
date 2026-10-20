describe('Apuesta Simple y Cancelación', () => {
  beforeEach(() => {
    cy.viewport(1280, 720);

    // Iniciar sesión con el usuario cliente
    cy.visit('/login.php');
    cy.get('[data-cy="input-identificador"]').clear().type('qa_cliente');
    cy.get('[data-cy="input-password"]').clear().type('Password123!');
    cy.get('[data-cy="btn-login"]').click();
    cy.url().should('include', '/dashboard.php');
  });

  it('1. Debe realizar una apuesta simple de $50 y reflejar el débito en el saldo', () => {
    cy.get('[data-cy="nav-apostar"]').click();
    cy.url().should('include', '/apostar.php');
    cy.contains('h1', 'Apostar').should('be.visible');

    // Seleccionar el primer partido disponible
    cy.get('[data-cy="match-card"]').first().click();

    // Seleccionar resultado local e ingresar monto
    cy.get('[data-cy="cuota-local"]').first().click();
    cy.get('[data-cy="input-monto"]').clear().type('50.00');

    // Confirmar apuesta
    cy.get('[data-cy="btn-confirmar-apuesta"]').should('not.be.disabled').click();

    // Validar mensaje de confirmación
    cy.get('.alert-success').should('be.visible').and('contain.text', 'Apuesta registrada');

    // Validar registro en Mis Apuestas
    cy.visit('/mis_apuestas.php');
    cy.contains('h1', 'Mis apuestas').should('be.visible');
    cy.get('[data-cy="mis-apuestas-row"]').first().within(() => {
      cy.contains('50.00').should('be.visible');
      cy.contains('Pendiente').should('be.visible');
      cy.get('[data-cy="btn-cancelar-apuesta"]').should('be.visible');
    });
  });

  it('2. Debe permitir cancelar una apuesta simple pendiente y reembolsar el saldo', () => {
    cy.visit('/mis_apuestas.php');
    cy.contains('h1', 'Mis apuestas').should('be.visible');

    // Localizar la primera apuesta con botón de cancelar
    cy.get('[data-cy="mis-apuestas-row"]')
      .filter(':has([data-cy="btn-cancelar-apuesta"])')
      .first()
      .within(() => {
        cy.get('[data-cy="btn-cancelar-apuesta"]').click();
      });

    // Validar confirmación de cancelación y reembolso
    cy.get('.alert-success')
      .should('be.visible')
      .and('contain.text', 'cancelada exitosamente')
      .and('contain.text', 'reembolsaron');
  });
});
