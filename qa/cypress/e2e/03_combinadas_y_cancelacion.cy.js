describe('Apuestas Combinadas (Parlay) y Cancelación de Boleto', () => {
  beforeEach(() => {
    cy.viewport(1280, 720);

    // Iniciar sesión con el usuario cliente
    cy.visit('/login.php');
    cy.get('[data-cy="input-identificador"]').clear().type('qa_cliente');
    cy.get('[data-cy="input-password"]').clear().type('Password123!');
    cy.get('[data-cy="btn-login"]').click();
    cy.url().should('include', '/dashboard.php');
  });

  it('1. Debe registrar un boleto combinado seleccionando al menos 2 partidos distintos', () => {
    cy.get('[data-cy="nav-apostar-combinada"]').click();
    cy.url().should('include', '/apostar_combinada.php');
    cy.contains('h1', 'Boleto combinado').should('be.visible');

    // Seleccionar local en el primer partido
    cy.get('[data-cy="match-card-combo"]').eq(0).within(() => {
      cy.get('[data-cy="combo-local"]').click();
    });

    // Seleccionar visita en el segundo partido
    cy.get('[data-cy="match-card-combo"]').eq(1).within(() => {
      cy.get('[data-cy="combo-visita"]').click();
    });

    // Verificar que la cuota total calculada no esté vacía
    cy.get('#preview-cuota-total').should('not.have.text', '—');

    // Ingresar monto de la apuesta combinada (mínimo 20.00)
    cy.get('[data-cy="input-monto-combo"]').clear().type('25.00');

    // El botón debe estar habilitado y enviarse
    cy.get('[data-cy="btn-confirmar-boleto"]').should('not.be.disabled').click();

    // Redirige a Mis Apuestas con mensaje de éxito
    cy.url().should('include', '/mis_apuestas.php');
    cy.get('.alert-success')
      .should('be.visible')
      .and('contain.text', 'Boleto combinado registrado');

    // Comprobar que aparece en la tabla de boletos combinados
    cy.get('[data-cy="mis-boletos-row"]').first().within(() => {
      cy.contains('25.00').should('be.visible');
      cy.contains('Pendiente').should('be.visible');
      cy.get('[data-cy="btn-cancelar-boleto"]').should('be.visible');
    });
  });

  it('2. Debe permitir cancelar un boleto combinado pendiente y reembolsar el saldo total', () => {
    cy.visit('/mis_apuestas.php');

    // Cancelar el primer boleto que tenga botón de cancelar
    cy.get('[data-cy="mis-boletos-row"]')
      .filter(':has([data-cy="btn-cancelar-boleto"])')
      .first()
      .within(() => {
        cy.get('[data-cy="btn-cancelar-boleto"]').click();
      });

    // Validar mensaje de confirmación y reembolso
    cy.get('.alert-success')
      .should('be.visible')
      .and('contain.text', 'cancelado exitosamente')
      .and('contain.text', 'reembolsaron');
  });
});
