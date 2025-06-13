package com.novemax.expense.service.impl;

import com.novemax.expense.model.Expense;
import com.novemax.expense.model.Group;
import com.novemax.expense.model.User;
import com.novemax.expense.repository.ExpenseRepository;
import com.novemax.expense.repository.GroupRepository;
import com.novemax.expense.service.BalanceService;
import java.math.RoundingMode;
import lombok.RequiredArgsConstructor;
import org.springframework.stereotype.Service;

import java.math.BigDecimal;
import java.time.YearMonth;
import java.util.*;
import java.util.stream.Collectors;

@Service
@RequiredArgsConstructor
public class BalanceServiceImpl implements BalanceService {

  private final ExpenseRepository expenseRepository;
  private final GroupRepository groupRepository;

  @Override
  public Map<String, Map<String, BigDecimal>> calculateBalances(UUID groupId, YearMonth yearMonth) {
    List<Expense> expenses = expenseRepository.findByGroup_Id(groupId).stream()
        .filter(e -> YearMonth.from(e.getDatePayment()).equals(yearMonth))
        .collect(Collectors.toList());

    Map<User, BigDecimal> userReceivables = new HashMap<>();
    Map<User, BigDecimal> userPayables = new HashMap<>();

    for (Expense expense : expenses) {
      BigDecimal individualShare = expense.getTotalValue()
          .divide(BigDecimal.valueOf(expense.getPayers().size()), 2, BigDecimal.ROUND_HALF_UP);

      for (User payer : expense.getPayers()) {
        userPayables.merge(payer, individualShare, BigDecimal::add);
      }

      userReceivables.merge(expense.getUserPayer(), expense.getTotalValue(), BigDecimal::add);
    }

    Set<User> allUsers = new HashSet<>();
    allUsers.addAll(userReceivables.keySet());
    allUsers.addAll(userPayables.keySet());

    Map<String, Map<String, BigDecimal>> result = new HashMap<>();
    for (User user : allUsers) {
      BigDecimal receivable = userReceivables.getOrDefault(user, BigDecimal.ZERO);
      BigDecimal payable = userPayables.getOrDefault(user, BigDecimal.ZERO);
      BigDecimal balance = receivable.subtract(payable);

      Map<String, BigDecimal> userBalance = new HashMap<>();
      userBalance.put("receber", receivable);
      userBalance.put("pagar", payable);
      userBalance.put("saldo", balance);
      result.put(user.getEmail(), userBalance);
    }

    return result;
  }

  @Override
  public Map<YearMonth, Map<String, Map<String, BigDecimal>>> calculateMonthlyBalances(UUID groupId) {
    Group group = groupRepository.findById(groupId)
        .orElseThrow(() -> new RuntimeException("Grupo não encontrado"));

    List<Expense> expenses = expenseRepository.findByGroup_Id(groupId);

    Map<YearMonth, Map<String, Map<String, BigDecimal>>> result = new TreeMap<>();

    for (Expense expense : expenses) {
      YearMonth month = YearMonth.from(expense.getDatePayment());

      Map<String, Map<String, BigDecimal>> monthBalances = result.computeIfAbsent(month, k -> new HashMap<>());

      String payerEmail = expense.getUserPayer().getEmail();
      List<User> payers = expense.getPayers();

      BigDecimal quota = expense.getTotalValue()
          .divide(BigDecimal.valueOf(payers.size()), 2, RoundingMode.HALF_UP);

      for (User user : payers) {
        String userEmail = user.getEmail();

        if (userEmail.equals(payerEmail)) continue;

        // payerEmail recebe de userEmail
        monthBalances
            .computeIfAbsent(userEmail, k -> new HashMap<>())
            .merge(payerEmail, quota, BigDecimal::add);
      }
    }

    return result;
  }

}
