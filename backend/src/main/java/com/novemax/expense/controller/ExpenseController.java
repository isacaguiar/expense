package com.novemax.expense.controller;

import com.novemax.expense.dto.request.ExpenseRequestDTO;
import com.novemax.expense.dto.response.ExpenseResponseDTO;
import com.novemax.expense.service.BalanceService;
import com.novemax.expense.service.ExpenseService;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.validation.Valid;
import java.math.BigDecimal;
import java.time.YearMonth;
import java.util.Map;
import lombok.RequiredArgsConstructor;
import org.springframework.format.annotation.DateTimeFormat;
import org.springframework.http.ResponseEntity;
import org.springframework.security.core.annotation.AuthenticationPrincipal;
import org.springframework.security.core.userdetails.UserDetails;
import org.springframework.web.bind.annotation.*;

import java.util.List;
import java.util.UUID;

@RestController
@RequestMapping("/expenses")
@RequiredArgsConstructor
@Tag(name = "Expense", description = "Expense management and balance calculation")
public class ExpenseController {

  private final ExpenseService expenseService;
  private final BalanceService balanceService;

  @PostMapping
  public ResponseEntity<ExpenseResponseDTO> createExpense(@AuthenticationPrincipal UserDetails user,
                                                          @Valid @RequestBody ExpenseRequestDTO dto) {
    return ResponseEntity.ok(expenseService.createExpense(user.getUsername(), dto));
  }

  @GetMapping("/group/{groupId}")
  public ResponseEntity<List<ExpenseResponseDTO>> getExpensesByGroup(@PathVariable UUID groupId) {
    return ResponseEntity.ok(expenseService.getExpensesByGroup(groupId));
  }

  @DeleteMapping("/{expenseId}")
  public ResponseEntity<Void> deleteExpense(@PathVariable UUID expenseId,
                                            @AuthenticationPrincipal UserDetails user) {
    expenseService.deleteExpense(expenseId, user.getUsername());
    return ResponseEntity.noContent().build();
  }

  @GetMapping("/group/{groupId}/balance")
  public ResponseEntity<Object> getBalanceForUser(@AuthenticationPrincipal UserDetails user,
                                                  @PathVariable UUID groupId) {
    return ResponseEntity.ok(expenseService.calculateBalanceForUser(user.getUsername(), groupId));
  }

  @GetMapping("/balance/{groupId}")
  public ResponseEntity<Object> calculateBalance(@AuthenticationPrincipal UserDetails user,
                                                 @PathVariable UUID groupId,
                                                 @PathVariable @DateTimeFormat(pattern = "yyyy-MM") YearMonth yearMonth) {
    return ResponseEntity.ok(expenseService.calculateBalanceForUser(user.getUsername(), groupId));
  }

//  @GetMapping("/group/{groupId}/balance/{yearMonth}")
//  public ResponseEntity<?> calculateGroupBalance(@PathVariable UUID groupId,
//                                                 @PathVariable @DateTimeFormat(pattern = "yyyy-MM") YearMonth yearMonth) {
//    return ResponseEntity.ok(balanceService.calculateBalances(groupId, yearMonth));
//  }

//  @GetMapping("/group/{groupId}/balance-by-month")
//  public ResponseEntity<Map<YearMonth, Map<String, Map<String, BigDecimal>>>> calculateMonthlyBalances(
//      @PathVariable UUID groupId) {
//    return ResponseEntity.ok(balanceService.calculateMonthlyBalances(groupId));
//  }

}